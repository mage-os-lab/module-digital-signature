<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Provider;

use MageOS\DigitalSignature\Exception\ProviderException;
use MageOS\DigitalSignature\Model\Document\Status;
use MageOS\DigitalSignature\Model\Provider\Docusign;
use MageOS\DigitalSignature\Model\Provider\Docusign\Client;
use MageOS\DigitalSignature\Model\Provider\ProviderConfig;
use MageOS\DigitalSignature\Model\Provider\Result\StartResult;
use MageOS\DigitalSignature\TestSupport\FakeDocument;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DocusignTest extends TestCase
{
    private Client&MockObject $client;
    private ProviderConfig&MockObject $config;
    private OrderRepositoryInterface&MockObject $orderRepository;
    private StoreManagerInterface&MockObject $storeManager;
    private Docusign $provider;

    protected function setUp(): void
    {
        $this->client = $this->createMock(Client::class);
        $this->config = $this->createMock(ProviderConfig::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);

        $this->provider = new Docusign(
            $this->client,
            $this->config,
            $this->orderRepository,
            $this->storeManager,
            new Json()
        );
    }

    private function makeDocument(): FakeDocument
    {
        $document = new FakeDocument();
        $document->documentId = 55;
        $document->setOrderId(9)
            ->setStoreId(1)
            ->setSignerEmail('cliente@example.com')
            ->setProviderCode(Docusign::CODE);

        return $document;
    }

    private function stubStoreBaseUrl(string $baseUrl = 'https://shop.example.com/'): void
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getBaseUrl')->willReturn($baseUrl);
        $this->storeManager->method('getStore')->with(1)->willReturn($store);
    }

    private function stubOrder(OrderInterface&MockObject $order): void
    {
        $this->orderRepository->method('get')->with(9)->willReturn($order);
    }

    public function testCodeAndLabel(): void
    {
        self::assertSame('docusign', $this->provider->getCode());
        self::assertSame('DocuSign', $this->provider->getLabel());
    }

    public function testIsEnabledTrueWhenFullyConfigured(): void
    {
        $this->config->method('isSetFlag')->willReturn(true);
        $this->config->method('get')->willReturnMap([
            ['docusign', 'integration_key', null, 'ikey'],
            ['docusign', 'user_id', null, 'user-1'],
        ]);
        $this->config->method('getSecret')->willReturn('pem-key');

        self::assertTrue($this->provider->isEnabled());
    }

    public function testIsEnabledFalseWhenDisabled(): void
    {
        $this->config->method('isSetFlag')->willReturn(false);

        self::assertFalse($this->provider->isEnabled());
    }

    public function testIsEnabledFalseWhenPrivateKeyMissing(): void
    {
        $this->config->method('isSetFlag')->willReturn(true);
        $this->config->method('get')->willReturnMap([
            ['docusign', 'integration_key', null, 'ikey'],
            ['docusign', 'user_id', null, 'user-1'],
        ]);
        $this->config->method('getSecret')->willReturn(null);

        self::assertFalse($this->provider->isEnabled());
    }

    public function testStartBuildsEnvelopeAndReturnsStartResult(): void
    {
        $document = $this->makeDocument();
        $this->stubStoreBaseUrl();

        $order = $this->createMock(OrderInterface::class);
        $order->method('getCustomerFirstname')->willReturn('Mario');
        $order->method('getCustomerLastname')->willReturn('Rossi');
        $this->stubOrder($order);

        $this->config->method('get')->willReturnMap([
            ['docusign', 'expiration_days', 1, '7'],
            ['docusign', 'notes', 1, null],
        ]);

        $captured = null;
        $this->client->expects(self::once())
            ->method('createEnvelope')
            ->with(1, self::isType('array'))
            ->willReturnCallback(function (int $storeId, array $envelopeData) use (&$captured) {
                $captured = $envelopeData;

                return ['envelopeId' => 'env-123', 'status' => 'sent'];
            });

        $result = $this->provider->start($document, '%PDF-1.4 contenuto', 'callback-token-123');

        self::assertInstanceOf(StartResult::class, $result);
        self::assertSame('env-123', $result->getProcessId());
        self::assertStringContainsString('env-123', (string)$result->getRawResponse());

        self::assertSame('Mario Rossi', $captured['recipients']['signers'][0]['name']);
        self::assertSame('cliente@example.com', $captured['recipients']['signers'][0]['email']);
        self::assertSame('{WSIGN#', $captured['recipients']['signers'][0]['tabs']['signHereTabs'][0]['anchorString']);
        self::assertSame('7', $captured['notification']['expirations']['expireAfter']);
        self::assertStringContainsString(
            'digitalsignature/callback/index/id/55/token/callback-token-123',
            $captured['eventNotification']['url']
        );
        self::assertStringStartsWith('https://shop.example.com/', $captured['eventNotification']['url']);
        self::assertSame(base64_encode('%PDF-1.4 contenuto'), $captured['documents'][0]['documentBase64']);
    }

    public function testStartThrowsWhenEmailInvalid(): void
    {
        $document = $this->makeDocument();
        $document->setSignerEmail('non-una-email');

        $this->expectException(ProviderException::class);
        $this->provider->start($document, '%PDF', 'token');
    }

    public function testStartThrowsWhenEmailMissing(): void
    {
        $document = $this->makeDocument();
        $document->setSignerEmail(null);

        $this->expectException(ProviderException::class);
        $this->provider->start($document, '%PDF', 'token');
    }

    public function testStartThrowsWhenEnvelopeIdMissingFromResponse(): void
    {
        $document = $this->makeDocument();
        $this->stubStoreBaseUrl();
        $order = $this->createMock(OrderInterface::class);
        $order->method('getCustomerFirstname')->willReturn('Mario');
        $order->method('getCustomerLastname')->willReturn('Rossi');
        $this->stubOrder($order);
        $this->config->method('get')->willReturn(null);

        $this->client->method('createEnvelope')->willReturn(['status' => 'created']);

        try {
            $this->provider->start($document, '%PDF', 'token');
            self::fail('Attesa ProviderException');
        } catch (ProviderException $e) {
            self::assertFalse($e->isRetryable());
        }
    }

    public function testStartFallsBackToCustomerNameWhenFirstLastEmpty(): void
    {
        $document = $this->makeDocument();
        $this->stubStoreBaseUrl();

        $order = $this->createMock(OrderInterface::class);
        $order->method('getCustomerFirstname')->willReturn('');
        $order->method('getCustomerLastname')->willReturn('');
        $order->method('getCustomerName')->willReturn('Ragione Sociale SRL');
        $this->stubOrder($order);
        $this->config->method('get')->willReturn(null);

        $captured = null;
        $this->client->method('createEnvelope')->willReturnCallback(
            function (int $storeId, array $data) use (&$captured) {
                $captured = $data;

                return ['envelopeId' => 'env-1'];
            }
        );

        $this->provider->start($document, '%PDF', 'token');

        self::assertSame('Ragione Sociale SRL', $captured['recipients']['signers'][0]['name']);
    }

    public function testStartFallsBackToBillingAddressNameWhenCustomerNameEmpty(): void
    {
        $document = $this->makeDocument();
        $this->stubStoreBaseUrl();

        $address = $this->createMock(OrderAddressInterface::class);
        $address->method('getName')->willReturn('Indirizzo Nome');

        $order = $this->createMock(OrderInterface::class);
        $order->method('getCustomerFirstname')->willReturn('');
        $order->method('getCustomerLastname')->willReturn('');
        $order->method('getCustomerName')->willReturn('');
        $order->method('getBillingAddress')->willReturn($address);
        $this->stubOrder($order);
        $this->config->method('get')->willReturn(null);

        $captured = null;
        $this->client->method('createEnvelope')->willReturnCallback(
            function (int $storeId, array $data) use (&$captured) {
                $captured = $data;

                return ['envelopeId' => 'env-1'];
            }
        );

        $this->provider->start($document, '%PDF', 'token');

        self::assertSame('Indirizzo Nome', $captured['recipients']['signers'][0]['name']);
    }

    public function testStartUsesGenericNameWhenOrderLookupFails(): void
    {
        $document = $this->makeDocument();
        $this->stubStoreBaseUrl();
        $this->orderRepository->method('get')->willThrowException(new \Exception('ordine non trovato'));
        $this->config->method('get')->willReturn(null);

        $captured = null;
        $this->client->method('createEnvelope')->willReturnCallback(
            function (int $storeId, array $data) use (&$captured) {
                $captured = $data;

                return ['envelopeId' => 'env-1'];
            }
        );

        $this->provider->start($document, '%PDF', 'token');

        self::assertSame('Customer', $captured['recipients']['signers'][0]['name']);
    }

    public function testFetchStatusReturnsStatusResult(): void
    {
        $document = $this->makeDocument();
        $document->setProviderProcessId('env-123');

        $this->client->expects(self::once())
            ->method('getEnvelopeStatus')
            ->with(1, 'env-123')
            ->willReturn(['status' => 'delivered']);

        $result = $this->provider->fetchStatus($document);

        self::assertSame('delivered', $result->getRawStatus());
    }

    public function testFetchStatusThrowsWhenNoProcessId(): void
    {
        $document = $this->makeDocument();

        $this->expectException(ProviderException::class);
        $this->provider->fetchStatus($document);
    }

    public function testFetchStatusThrowsWhenResponseHasNoStatus(): void
    {
        $document = $this->makeDocument();
        $document->setProviderProcessId('env-123');
        $this->client->method('getEnvelopeStatus')->willReturn(['other' => 'field']);

        $this->expectException(ProviderException::class);
        $this->provider->fetchStatus($document);
    }

    public function testDownloadSignedPdfDelegatesToClient(): void
    {
        $document = $this->makeDocument();
        $document->setProviderProcessId('env-123');

        $this->client->expects(self::once())
            ->method('downloadDocument')
            ->with(1, 'env-123')
            ->willReturn('%PDF-signed');

        self::assertSame('%PDF-signed', $this->provider->downloadSignedPdf($document));
    }

    public function testDownloadSignedPdfThrowsWhenNoProcessId(): void
    {
        $document = $this->makeDocument();

        $this->expectException(ProviderException::class);
        $this->provider->downloadSignedPdf($document);
    }

    public function testCancelIsNoOpWhenNoProcessId(): void
    {
        $document = $this->makeDocument();

        $this->client->expects(self::never())->method('voidEnvelope');
        $this->provider->cancel($document);
    }

    public function testCancelCallsVoidEnvelope(): void
    {
        $document = $this->makeDocument();
        $document->setProviderProcessId('env-123');

        $this->client->expects(self::once())
            ->method('voidEnvelope')
            ->with(1, 'env-123', self::isType('string'));

        $this->provider->cancel($document);
    }

    public function testMapStatusMapsAllKnownProviderStatuses(): void
    {
        self::assertSame(Status::SENT, $this->provider->mapStatus('sent'));
        self::assertSame(Status::SENT, $this->provider->mapStatus('delivered'));
        self::assertSame(Status::SIGNED, $this->provider->mapStatus('completed'));
        self::assertSame(Status::DECLINED, $this->provider->mapStatus('declined'));
        self::assertSame(Status::EXPIRED, $this->provider->mapStatus('voided'));
    }

    public function testMapStatusReturnsNullForUnknownStatus(): void
    {
        self::assertNull($this->provider->mapStatus('qualcosa-di-nuovo'));
    }

    public function testMapStatusConfiguredMappingWinsOverHardcodedDefault(): void
    {
        $this->config->method('mapConfiguredStatus')->with(Docusign::CODE, 'completed')
            ->willReturn(Status::CANCELED);

        self::assertSame(Status::CANCELED, $this->provider->mapStatus('completed'));
    }

    public function testMapStatusUsesConfiguredMappingForNewStatus(): void
    {
        $this->config->method('mapConfiguredStatus')->with(Docusign::CODE, 'corrected')
            ->willReturn(Status::SENT);

        self::assertSame(Status::SENT, $this->provider->mapStatus('corrected'));
    }
}
