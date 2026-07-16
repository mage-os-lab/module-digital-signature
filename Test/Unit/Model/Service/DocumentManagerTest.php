<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Service;

use MageOS\DigitalSignature\Api\DocumentRepositoryInterface;
use MageOS\DigitalSignature\Api\SignProviderInterface;
use MageOS\DigitalSignature\Api\SignProviderPoolInterface;
use MageOS\DigitalSignature\Exception\ProviderException;
use MageOS\DigitalSignature\Model\Document\Status;
use MageOS\DigitalSignature\Model\DocumentFactory;
use MageOS\DigitalSignature\Model\Queue\Publisher;
use MageOS\DigitalSignature\Model\Service\DocumentManager;
use MageOS\DigitalSignature\Model\Service\ProductionModeGuard;
use MageOS\DigitalSignature\Model\Service\TriggerHandler;
use MageOS\DigitalSignature\Model\Template\Source\Trigger;
use MageOS\DigitalSignature\TestSupport\FakeDocument;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DocumentManagerTest extends TestCase
{
    private DocumentRepositoryInterface&MockObject $documentRepository;
    private Publisher&MockObject $publisher;
    private TriggerHandler&MockObject $triggerHandler;
    private SignProviderPoolInterface&MockObject $providerPool;
    private ProductionModeGuard&MockObject $productionModeGuard;
    private DocumentManager $manager;

    private FakeDocument $newDocument;

    protected function setUp(): void
    {
        $this->documentRepository = $this->createMock(DocumentRepositoryInterface::class);
        $this->publisher = $this->createMock(Publisher::class);
        $this->triggerHandler = $this->createMock(TriggerHandler::class);
        $this->providerPool = $this->createMock(SignProviderPoolInterface::class);
        $this->productionModeGuard = $this->createMock(ProductionModeGuard::class);
        $this->productionModeGuard->method('resolveProviderCode')->willReturnArgument(0);

        $this->newDocument = new FakeDocument();
        $this->newDocument->documentId = 99;
        $documentFactory = $this->createMock(DocumentFactory::class);
        $documentFactory->method('create')->willReturn($this->newDocument);

        $this->manager = new DocumentManager(
            $this->documentRepository,
            $documentFactory,
            $this->publisher,
            $this->triggerHandler,
            $this->providerPool,
            $this->productionModeGuard
        );
    }

    public function testGenerateManualDelegatesToTriggerHandler(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $this->triggerHandler->expects(self::once())->method('handle')->with($order, Trigger::MANUAL);

        $this->manager->generateManual($order);
    }

    public function testRegenerateRejectsInactiveDocument(): void
    {
        $old = new FakeDocument();
        $old->setIsActive(false);
        $this->documentRepository->method('getById')->willReturn($old);

        $this->expectException(LocalizedException::class);

        $this->manager->regenerate(10);
    }

    public function testRegenerateCancelsAtProviderAndCreatesNewDocument(): void
    {
        $old = $this->buildOldDocument();
        $this->documentRepository->method('getById')->willReturn($old);
        $this->documentRepository->method('save')->willReturnArgument(0);

        $provider = $this->createMock(SignProviderInterface::class);
        $provider->expects(self::once())->method('cancel')->with($old);
        $this->providerPool->expects(self::once())->method('get')->with('wssign')->willReturn($provider);

        $this->publisher->expects(self::once())->method('publishProcess')->with(99);

        $newId = $this->manager->regenerate(10);

        self::assertSame(99, $newId);
        // Il vecchio documento esce dall'indice attivo e resta in storico
        self::assertFalse($old->getIsActive());
        self::assertSame(Status::CANCELED, $old->getStatus());
        // Il nuovo eredita la combinazione e riparte da pending
        self::assertSame(42, $this->newDocument->getOrderId());
        self::assertSame(7, $this->newDocument->getTemplateId());
        self::assertSame(Status::PENDING, $this->newDocument->getStatus());
        self::assertTrue($this->newDocument->getIsActive());
    }

    public function testRegenerateContinuesWhenProviderCancelFails(): void
    {
        $old = $this->buildOldDocument();
        $this->documentRepository->method('getById')->willReturn($old);
        $this->documentRepository->method('save')->willReturnArgument(0);

        $provider = $this->createMock(SignProviderInterface::class);
        $provider->method('cancel')->willThrowException(ProviderException::retryable(__('provider giù')));
        $this->providerPool->method('get')->willReturn($provider);

        // La rigenerazione non deve fallire: il nuovo documento parte comunque
        $this->publisher->expects(self::once())->method('publishProcess')->with(99);

        self::assertSame(99, $this->manager->regenerate(10));
    }

    public function testRegenerateSkipsProviderCancelWithoutProcessId(): void
    {
        $old = $this->buildOldDocument(processId: null);
        $this->documentRepository->method('getById')->willReturn($old);
        $this->documentRepository->method('save')->willReturnArgument(0);

        $this->providerPool->expects(self::never())->method('get');

        $this->manager->regenerate(10);
    }

    public function testRegenerateForcesDummyWhenProductionNotConfirmed(): void
    {
        $old = $this->buildOldDocument();
        $this->documentRepository->method('getById')->willReturn($old);
        $this->documentRepository->method('save')->willReturnArgument(0);
        $this->providerPool->method('get')->willReturn($this->createMock(SignProviderInterface::class));

        $guard = $this->createMock(ProductionModeGuard::class);
        $guard->expects(self::once())->method('resolveProviderCode')->with('wssign', 1)->willReturn('dummy');
        $documentFactory = $this->createMock(DocumentFactory::class);
        $documentFactory->method('create')->willReturn($this->newDocument);
        $manager = new DocumentManager(
            $this->documentRepository,
            $documentFactory,
            $this->publisher,
            $this->triggerHandler,
            $this->providerPool,
            $guard
        );

        $manager->regenerate(10);

        self::assertSame('dummy', $this->newDocument->getProviderCode());
    }

    private function buildOldDocument(?string $processId = 'guid-123'): FakeDocument
    {
        $old = new FakeDocument();
        $old->documentId = 10;
        $old->setOrderId(42);
        $old->setOrderItemId(0);
        $old->setTemplateId(7);
        $old->setStoreId(1);
        $old->setProviderCode('wssign');
        $old->setStatus(Status::SENT);
        $old->setIsActive(true);
        $old->setTriggerCode('order_place');
        $old->setSignerEmail('cliente@example.com');
        $old->setSignerPhone('+393331234567');
        $old->setProviderProcessId($processId);

        return $old;
    }
}
