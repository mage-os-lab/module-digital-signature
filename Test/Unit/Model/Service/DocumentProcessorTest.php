<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Service;

use MageOS\DigitalSignature\Api\DocumentRepositoryInterface;
use MageOS\DigitalSignature\Api\DocumentStorageInterface;
use MageOS\DigitalSignature\Api\SignProviderInterface;
use MageOS\DigitalSignature\Api\SignProviderPoolInterface;
use MageOS\DigitalSignature\Exception\ProviderException;
use MageOS\DigitalSignature\Model\Document\Status;
use MageOS\DigitalSignature\Model\Notification\Notifier;
use MageOS\DigitalSignature\Model\Pdf\TagReplacer;
use MageOS\DigitalSignature\Model\Provider\ProviderConfig;
use MageOS\DigitalSignature\Model\Provider\Result\StartResult;
use MageOS\DigitalSignature\Model\Quota\Calculator;
use MageOS\DigitalSignature\Model\Quota\QuotaStatus;
use MageOS\DigitalSignature\Model\ResourceModel\Template as TemplateResource;
use MageOS\DigitalSignature\Model\Service\DocumentProcessor;
use MageOS\DigitalSignature\TestSupport\FakeDocument;
use Magento\Framework\Filesystem;
use Magento\Framework\Math\Random;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\ResourceModel\Order\Invoice\CollectionFactory as InvoiceCollectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DocumentProcessorTest extends TestCase
{
    private DocumentRepositoryInterface&MockObject $documentRepository;
    private DocumentStorageInterface&MockObject $storage;
    private SignProviderPoolInterface&MockObject $providerPool;
    private Calculator&MockObject $quotaCalculator;
    private ProviderConfig&MockObject $providerConfig;
    private SignProviderInterface&MockObject $provider;
    private DocumentProcessor $processor;

    protected function setUp(): void
    {
        $this->documentRepository = $this->createMock(DocumentRepositoryInterface::class);
        $this->documentRepository->method('save')->willReturnArgument(0);
        $this->storage = $this->createMock(DocumentStorageInterface::class);
        $this->storage->method('read')->willReturn('%PDF-fake%');
        $this->providerPool = $this->createMock(SignProviderPoolInterface::class);
        $this->quotaCalculator = $this->createMock(Calculator::class);
        $this->providerConfig = $this->createMock(ProviderConfig::class);

        $this->provider = $this->createMock(SignProviderInterface::class);
        $this->providerPool->method('get')->with('wssign')->willReturn($this->provider);

        $random = $this->createMock(Random::class);
        $random->method('getRandomString')->willReturn('a-random-token');
        $filesystem = $this->createMock(Filesystem::class);

        $this->processor = new DocumentProcessor(
            $this->documentRepository,
            $this->createMock(TemplateResource::class),
            $this->createMock(TagReplacer::class),
            $this->storage,
            $this->providerPool,
            $filesystem,
            $random,
            $this->createMock(Notifier::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(OrderRepositoryInterface::class),
            $this->createMock(InvoiceCollectionFactory::class),
            $this->quotaCalculator,
            $this->providerConfig
        );
    }

    public function testSendBlocksWhenQuotaExhaustedAndBehaviorIsBlockDispatch(): void
    {
        $document = $this->generatedDocument();
        $this->quotaCalculator->method('calculate')->with('wssign', 1)
            ->willReturn($this->exhaustedStatus());
        $this->providerConfig->method('get')->with('wssign', 'exceeded_behavior', 1)
            ->willReturn('block_dispatch');

        $this->provider->expects(self::never())->method('start');

        try {
            $this->processor->send($document);
            self::fail('Expected ProviderException was not thrown.');
        } catch (ProviderException $e) {
            self::assertFalse($e->isRetryable());
            self::assertStringContainsString('wssign', $e->getMessage());
        }
    }

    public function testSendAllowsDispatchWhenBehaviorIsAllowAndLog(): void
    {
        $document = $this->generatedDocument();
        $this->quotaCalculator->method('calculate')->willReturn($this->exhaustedStatus());
        $this->providerConfig->method('get')->willReturn('allow_and_log');

        $this->provider->expects(self::once())->method('start')
            ->willReturn(new StartResult('proc-1'));

        $this->processor->send($document);

        self::assertSame(Status::SENT, $document->getStatus());
    }

    public function testSendAllowsDispatchWhenQuotaNotExhausted(): void
    {
        $document = $this->generatedDocument();
        $this->quotaCalculator->method('calculate')->willReturn(
            new QuotaStatus('wssign', true, 'prepaid_pool', 100, 50, 50, 50.0, false, false, '50/100 used')
        );
        $this->providerConfig->method('get')->willReturn('block_dispatch');

        $this->provider->expects(self::once())->method('start')
            ->willReturn(new StartResult('proc-1'));

        $this->processor->send($document);

        self::assertSame(Status::SENT, $document->getStatus());
    }

    public function testSendAllowsDispatchWhenQuotaTrackingDisabled(): void
    {
        $document = $this->generatedDocument();
        $this->quotaCalculator->method('calculate')->willReturn(
            new QuotaStatus('wssign', false, 'prepaid_pool', 0, 0, 0, 0.0, false, false, 'disabled')
        );
        $this->providerConfig->method('get')->willReturn('block_dispatch');

        $this->provider->expects(self::once())->method('start')
            ->willReturn(new StartResult('proc-1'));

        $this->processor->send($document);

        self::assertSame(Status::SENT, $document->getStatus());
    }

    public function testSendDefaultsToAllowWhenExceededBehaviorNotConfigured(): void
    {
        $document = $this->generatedDocument();
        $this->quotaCalculator->method('calculate')->willReturn($this->exhaustedStatus());
        $this->providerConfig->method('get')->willReturn(null);

        $this->provider->expects(self::once())->method('start')
            ->willReturn(new StartResult('proc-1'));

        $this->processor->send($document);

        self::assertSame(Status::SENT, $document->getStatus());
    }

    private function generatedDocument(): FakeDocument
    {
        $document = new FakeDocument();
        $document->documentId = 1;
        $document->setProviderCode('wssign');
        $document->setStoreId(1);
        $document->setStatus(Status::GENERATED);
        $document->setPdfPath('order_1/document_1.pdf');

        return $document;
    }

    private function exhaustedStatus(): QuotaStatus
    {
        return new QuotaStatus('wssign', true, 'prepaid_pool', 100, 100, 0, 100.0, true, true, '100/100 used');
    }
}
