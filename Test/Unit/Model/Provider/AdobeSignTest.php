<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Provider;

use MageOS\DigitalSignature\Exception\ProviderException;
use MageOS\DigitalSignature\Model\Document\Status;
use MageOS\DigitalSignature\Model\Provider\AdobeSign;
use MageOS\DigitalSignature\Model\Provider\AdobeSign\Client;
use MageOS\DigitalSignature\Model\Provider\ProviderConfig;
use MageOS\DigitalSignature\Model\Provider\Result\StartResult;
use MageOS\DigitalSignature\TestSupport\FakeDocument;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AdobeSignTest extends TestCase
{
    private Client&MockObject $client;
    private ProviderConfig&MockObject $config;
    private AdobeSign $provider;

    protected function setUp(): void
    {
        $this->client = $this->createMock(Client::class);
        $this->config = $this->createMock(ProviderConfig::class);

        $this->provider = new AdobeSign($this->client, $this->config, new Json());
    }

    private function makeDocument(): FakeDocument
    {
        $document = new FakeDocument();
        $document->documentId = 55;
        $document->setOrderId(9)
            ->setStoreId(1)
            ->setSignerEmail('cliente@example.com')
            ->setProviderCode(AdobeSign::CODE);

        return $document;
    }

    public function testCodeAndLabel(): void
    {
        self::assertSame('adobesign', $this->provider->getCode());
        self::assertSame('Adobe Acrobat Sign', $this->provider->getLabel());
    }

    public function testIsEnabledTrueWhenFullyConfigured(): void
    {
        $this->config->method('isSetFlag')->willReturn(true);
        $this->config->method('get')->willReturnMap([
            ['adobesign', 'client_id', null, 'client-1'],
            ['adobesign', 'api_access_point', null, 'https://api.na1.adobesign.com'],
        ]);
        $this->config->method('getSecret')->willReturn('secret');

        self::assertTrue($this->provider->isEnabled());
    }

    public function testIsEnabledFalseWhenDisabled(): void
    {
        $this->config->method('isSetFlag')->willReturn(false);

        self::assertFalse($this->provider->isEnabled());
    }

    public function testIsEnabledFalseWhenApiAccessPointMissing(): void
    {
        $this->config->method('isSetFlag')->willReturn(true);
        $this->config->method('get')->willReturnMap([
            ['adobesign', 'client_id', null, 'client-1'],
            ['adobesign', 'api_access_point', null, null],
        ]);
        $this->config->method('getSecret')->willReturn('secret');

        self::assertFalse($this->provider->isEnabled());
    }

    public function testStartBuildsAgreementAndReturnsStartResult(): void
    {
        $document = $this->makeDocument();
        $this->config->method('get')->willReturnMap([
            ['adobesign', 'expiration_days', 1, '7'],
            ['adobesign', 'notes', 1, null],
        ]);

        $captured = null;
        $this->client->expects(self::once())
            ->method('createAgreement')
            ->with(1, self::isType('array'), 'documento-ordine-9.pdf', '%PDF-1.4 contenuto')
            ->willReturnCallback(function (int $storeId, array $agreementData) use (&$captured) {
                $captured = $agreementData;

                return ['id' => 'agreement-123', 'status' => 'OUT_FOR_SIGNATURE'];
            });

        $result = $this->provider->start($document, '%PDF-1.4 contenuto', 'callback-token-123');

        self::assertInstanceOf(StartResult::class, $result);
        self::assertSame('agreement-123', $result->getProcessId());
        self::assertStringContainsString('agreement-123', (string)$result->getRawResponse());

        self::assertSame('cliente@example.com', $captured['participantSetsInfo'][0]['memberInfos'][0]['email']);
        self::assertSame('SIGNER', $captured['participantSetsInfo'][0]['role']);
        self::assertSame('IN_PROCESS', $captured['state']);
        self::assertSame(7, $captured['daysUntilSigningDeadline']);
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

    public function testStartThrowsWhenAgreementIdMissingFromResponse(): void
    {
        $document = $this->makeDocument();
        $this->config->method('get')->willReturn(null);
        $this->client->method('createAgreement')->willReturn(['status' => 'DRAFT']);

        try {
            $this->provider->start($document, '%PDF', 'token');
            self::fail('Attesa ProviderException');
        } catch (ProviderException $e) {
            self::assertFalse($e->isRetryable());
        }
    }

    public function testFetchStatusReturnsStatusResult(): void
    {
        $document = $this->makeDocument();
        $document->setProviderProcessId('agreement-123');

        $this->client->expects(self::once())
            ->method('getAgreementStatus')
            ->with(1, 'agreement-123')
            ->willReturn(['status' => 'OUT_FOR_SIGNATURE']);

        $result = $this->provider->fetchStatus($document);

        self::assertSame('OUT_FOR_SIGNATURE', $result->getRawStatus());
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
        $document->setProviderProcessId('agreement-123');
        $this->client->method('getAgreementStatus')->willReturn(['other' => 'field']);

        $this->expectException(ProviderException::class);
        $this->provider->fetchStatus($document);
    }

    public function testDownloadSignedPdfDelegatesToClient(): void
    {
        $document = $this->makeDocument();
        $document->setProviderProcessId('agreement-123');

        $this->client->expects(self::once())
            ->method('downloadCombinedDocument')
            ->with(1, 'agreement-123')
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

        $this->client->expects(self::never())->method('cancelAgreement');
        $this->provider->cancel($document);
    }

    public function testCancelCallsCancelAgreement(): void
    {
        $document = $this->makeDocument();
        $document->setProviderProcessId('agreement-123');

        $this->client->expects(self::once())
            ->method('cancelAgreement')
            ->with(1, 'agreement-123', self::isType('string'));

        $this->provider->cancel($document);
    }

    public function testMapStatusMapsAllKnownProviderStatuses(): void
    {
        self::assertSame(Status::SENT, $this->provider->mapStatus('OUT_FOR_SIGNATURE'));
        self::assertSame(Status::SENT, $this->provider->mapStatus('OUT_FOR_APPROVAL'));
        self::assertSame(Status::SIGNED, $this->provider->mapStatus('SIGNED'));
        self::assertSame(Status::SIGNED, $this->provider->mapStatus('APPROVED'));
        self::assertSame(Status::DECLINED, $this->provider->mapStatus('REJECTED'));
        self::assertSame(Status::DECLINED, $this->provider->mapStatus('CANCELLED'));
        self::assertSame(Status::EXPIRED, $this->provider->mapStatus('EXPIRED'));
    }

    public function testMapStatusReturnsNullForUnknownStatus(): void
    {
        self::assertNull($this->provider->mapStatus('qualcosa-di-nuovo'));
    }
}
