<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Service;

use MageOS\DigitalSignature\Api\DocumentRepositoryInterface;
use MageOS\DigitalSignature\Model\Document\Status;
use MageOS\DigitalSignature\Model\DocumentFactory;
use MageOS\DigitalSignature\Model\Queue\Publisher;
use MageOS\DigitalSignature\Model\ResourceModel\Template as TemplateResource;
use MageOS\DigitalSignature\Model\Service\ProductionModeGuard;
use MageOS\DigitalSignature\Model\Service\TriggerHandler;
use MageOS\DigitalSignature\TestSupport\FakeDocument;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TriggerHandlerTest extends TestCase
{
    private ScopeConfigInterface&MockObject $scopeConfig;
    private TemplateResource&MockObject $templateResource;
    private DocumentRepositoryInterface&MockObject $documentRepository;
    private Publisher&MockObject $publisher;
    private LoggerInterface&MockObject $logger;
    private ProductionModeGuard&MockObject $productionModeGuard;
    private TriggerHandler $handler;

    /** @var FakeDocument[] documenti creati dalla factory durante il test */
    private array $createdDocuments = [];

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->templateResource = $this->createMock(TemplateResource::class);
        $this->documentRepository = $this->createMock(DocumentRepositoryInterface::class);
        $this->publisher = $this->createMock(Publisher::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->productionModeGuard = $this->createMock(ProductionModeGuard::class);
        $this->productionModeGuard->method('resolveProviderCode')->willReturnArgument(0);
        $this->createdDocuments = [];

        $this->handler = $this->buildHandler($this->productionModeGuard);
    }

    private function buildHandler(ProductionModeGuard&MockObject $productionModeGuard): TriggerHandler
    {
        $documentFactory = $this->createMock(DocumentFactory::class);
        $documentFactory->method('create')->willReturnCallback(function (): FakeDocument {
            $document = new FakeDocument();
            $this->createdDocuments[] = $document;

            return $document;
        });

        return new TriggerHandler(
            $this->scopeConfig,
            $this->templateResource,
            $documentFactory,
            $this->documentRepository,
            $this->publisher,
            $this->logger,
            $productionModeGuard
        );
    }

    public function testDoesNothingWhenModuleDisabled(): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturn(false);
        $this->templateResource->expects(self::never())->method('getActiveCartTemplateRows');
        $this->documentRepository->expects(self::never())->method('save');

        $this->handler->handle($this->buildOrder(), 'order_place');
    }

    public function testDoesNothingWithoutConfiguredProvider(): void
    {
        $this->configureModule(provider: '');
        $this->templateResource->expects(self::never())->method('getActiveCartTemplateRows');

        $this->handler->handle($this->buildOrder(), 'order_place');
    }

    public function testCartTemplateWithMatchingTriggerCreatesDocument(): void
    {
        $this->configureModule();
        $this->templateResource->method('getActiveCartTemplateRows')->willReturn([
            ['template_id' => '7', 'trigger_code' => 'order_place', 'is_required' => '1'],
        ]);
        $this->templateResource->method('getProductAssignmentRows')->willReturn([]);

        $this->documentRepository->expects(self::once())->method('save')->willReturnArgument(0);
        $this->documentRepository->expects(self::once())->method('addLog');
        $this->publisher->expects(self::once())->method('publishProcess')->with(55);

        $this->handler->handle($this->buildOrder(), 'order_place');

        self::assertCount(1, $this->createdDocuments);
        $document = $this->createdDocuments[0];
        self::assertSame(42, $document->getOrderId());
        self::assertSame(0, $document->getOrderItemId(), 'Scope carrello = order_item_id 0');
        self::assertSame(7, $document->getTemplateId());
        self::assertSame('dummy', $document->getProviderCode());
        self::assertSame(Status::PENDING, $document->getStatus());
        self::assertSame('order_place', $document->getTriggerCode());
        self::assertSame('cliente@example.com', $document->getSignerEmail());
        self::assertSame('+393331234567', $document->getSignerPhone());
    }

    public function testCartTemplateWithDifferentTriggerIsSkipped(): void
    {
        $this->configureModule();
        $this->templateResource->method('getActiveCartTemplateRows')->willReturn([
            ['template_id' => '7', 'trigger_code' => 'invoice_paid', 'is_required' => '1'],
        ]);
        $this->templateResource->method('getProductAssignmentRows')->willReturn([]);
        $this->documentRepository->expects(self::never())->method('save');

        $this->handler->handle($this->buildOrder(), 'order_place');
    }

    public function testOptionalTemplateSkippedWhenCustomerDidNotRequest(): void
    {
        $this->configureModule();
        $this->templateResource->method('getActiveCartTemplateRows')->willReturn([
            ['template_id' => '7', 'trigger_code' => 'order_place', 'is_required' => '0'],
        ]);
        $this->templateResource->method('getProductAssignmentRows')->willReturn([]);
        $this->documentRepository->expects(self::never())->method('save');

        $this->handler->handle($this->buildOrder(requested: 0), 'order_place');
    }

    public function testOptionalTemplateCreatedWhenCustomerRequested(): void
    {
        $this->configureModule();
        $this->templateResource->method('getActiveCartTemplateRows')->willReturn([
            ['template_id' => '7', 'trigger_code' => 'order_place', 'is_required' => '0'],
        ]);
        $this->templateResource->method('getProductAssignmentRows')->willReturn([]);
        $this->documentRepository->expects(self::once())->method('save')->willReturnArgument(0);

        $this->handler->handle($this->buildOrder(requested: 1), 'order_place');
    }

    public function testEmptyTemplateTriggerFallsBackToGlobalDefault(): void
    {
        $this->configureModule(defaultTrigger: 'invoice_paid');
        $this->templateResource->method('getActiveCartTemplateRows')->willReturn([
            ['template_id' => '7', 'trigger_code' => '', 'is_required' => '1'],
        ]);
        $this->templateResource->method('getProductAssignmentRows')->willReturn([]);
        $this->documentRepository->expects(self::once())->method('save')->willReturnArgument(0);

        $this->handler->handle($this->buildOrder(), 'invoice_paid');
    }

    public function testProductOverrideWinsOverTemplateTrigger(): void
    {
        $this->configureModule(defaultTrigger: 'order_place');
        $this->templateResource->method('getActiveCartTemplateRows')->willReturn([]);
        $this->templateResource->method('getProductAssignmentRows')->willReturn([
            [
                'product_id' => '10',
                'template_id' => '3',
                'trigger_code' => 'invoice_paid',        // override sul prodotto
                'template_trigger_code' => 'order_place', // trigger del template
                'is_required' => '1',
            ],
        ]);
        $this->documentRepository->expects(self::once())->method('save')->willReturnArgument(0);

        $order = $this->buildOrder(items: [
            $this->buildItem(itemId: 100, productId: 10),
        ]);
        $this->handler->handle($order, 'invoice_paid');

        self::assertSame(100, $this->createdDocuments[0]->getOrderItemId());
        self::assertSame(3, $this->createdDocuments[0]->getTemplateId());
    }

    public function testChildOrderItemsAreSkipped(): void
    {
        $this->configureModule();
        $this->templateResource->method('getActiveCartTemplateRows')->willReturn([]);
        $this->templateResource->method('getProductAssignmentRows')->willReturn([
            [
                'product_id' => '10',
                'template_id' => '3',
                'trigger_code' => 'order_place',
                'template_trigger_code' => '',
                'is_required' => '1',
            ],
        ]);
        $this->documentRepository->expects(self::once())->method('save')->willReturnArgument(0);

        $order = $this->buildOrder(items: [
            $this->buildItem(itemId: 100, productId: 10),
            $this->buildItem(itemId: 101, productId: 10, parentItemId: 100), // figlio: da saltare
        ]);
        $this->handler->handle($order, 'order_place');

        self::assertCount(1, $this->createdDocuments);
        self::assertSame(100, $this->createdDocuments[0]->getOrderItemId());
    }

    public function testDuplicateDocumentIsSilentlySkipped(): void
    {
        $this->configureModule();
        $this->templateResource->method('getActiveCartTemplateRows')->willReturn([
            ['template_id' => '7', 'trigger_code' => 'order_place', 'is_required' => '1'],
        ]);
        $this->templateResource->method('getProductAssignmentRows')->willReturn([]);

        $this->documentRepository->method('save')->willThrowException(new AlreadyExistsException(__('dup')));
        $this->documentRepository->expects(self::never())->method('addLog');
        $this->publisher->expects(self::never())->method('publishProcess');
        $this->logger->expects(self::never())->method('error');

        $this->handler->handle($this->buildOrder(), 'order_place');
    }

    public function testGenericSaveErrorIsLoggedAndNotPropagated(): void
    {
        $this->configureModule();
        $this->templateResource->method('getActiveCartTemplateRows')->willReturn([
            ['template_id' => '7', 'trigger_code' => 'order_place', 'is_required' => '1'],
        ]);
        $this->templateResource->method('getProductAssignmentRows')->willReturn([]);

        $this->documentRepository->method('save')->willThrowException(new \RuntimeException('db down'));
        $this->logger->expects(self::once())->method('error');
        $this->publisher->expects(self::never())->method('publishProcess');

        $this->handler->handle($this->buildOrder(), 'order_place');
    }

    public function testProviderIsForcedToDummyWhenProductionNotConfirmed(): void
    {
        $this->configureModule(provider: 'wssign');
        $this->templateResource->method('getActiveCartTemplateRows')->willReturn([
            ['template_id' => '7', 'trigger_code' => 'order_place', 'is_required' => '1'],
        ]);
        $this->templateResource->method('getProductAssignmentRows')->willReturn([]);
        $this->documentRepository->expects(self::once())->method('save')->willReturnArgument(0);
        $this->logger->expects(self::once())->method('warning');

        $guard = $this->createMock(ProductionModeGuard::class);
        $guard->expects(self::once())->method('resolveProviderCode')->with('wssign', 1)->willReturn('dummy');
        $handler = $this->buildHandler($guard);

        $handler->handle($this->buildOrder(), 'order_place');

        self::assertSame('dummy', $this->createdDocuments[0]->getProviderCode());
    }

    private function configureModule(string $provider = 'dummy', string $defaultTrigger = 'order_place'): void
    {
        $this->scopeConfig->method('isSetFlag')
            ->with('digital_signature/general/enabled')
            ->willReturn(true);
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['digital_signature/general/provider', 'store', 1, $provider],
            ['digital_signature/general/default_trigger', 'store', 1, $defaultTrigger],
        ]);
    }

    private function buildOrder(int|null $requested = 1, array $items = []): OrderInterface&MockObject
    {
        $billing = new class {
            public function getTelephone(): string
            {
                return '+393331234567';
            }
        };

        $order = $this->createMock(OrderInterface::class);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getEntityId')->willReturn(42);
        $order->method('getCustomerEmail')->willReturn('cliente@example.com');
        $order->method('getBillingAddress')->willReturn($billing);
        $order->method('getData')->with('digitalsignature_requested')->willReturn($requested);
        $order->method('getItems')->willReturn($items);

        return $order;
    }

    private function buildItem(int $itemId, int $productId, ?int $parentItemId = null): object
    {
        return new class ($itemId, $productId, $parentItemId) {
            public function __construct(
                private readonly int $itemId,
                private readonly int $productId,
                private readonly ?int $parentItemId
            ) {
            }

            public function getItemId(): int
            {
                return $this->itemId;
            }

            public function getProductId(): int
            {
                return $this->productId;
            }

            public function getParentItemId(): ?int
            {
                return $this->parentItemId;
            }
        };
    }
}
