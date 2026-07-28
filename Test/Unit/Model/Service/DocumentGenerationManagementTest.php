<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Service;

use MageOS\DigitalSignature\Model\Service\ApiConsumerScope;
use MageOS\DigitalSignature\Model\Service\DocumentGenerationManagement;
use MageOS\DigitalSignature\Model\Service\DocumentManager;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DocumentGenerationManagementTest extends TestCase
{
    private OrderRepositoryInterface&MockObject $orderRepository;
    private DocumentManager&MockObject $documentManager;
    private ApiConsumerScope&MockObject $scope;
    private DocumentGenerationManagement $service;

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->documentManager = $this->createMock(DocumentManager::class);
        $this->scope = $this->createMock(ApiConsumerScope::class);
        $this->service = new DocumentGenerationManagement(
            $this->orderRepository,
            $this->documentManager,
            $this->scope
        );
    }

    public function testGenerateChecksScopeThenDelegatesToDocumentManager(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $this->scope->expects(self::once())->method('assertOrderInScope')->with(42);
        $this->orderRepository->method('get')->with(42)->willReturn($order);
        $this->documentManager->expects(self::once())->method('generateManual')->with($order);

        $this->service->generate(42);
    }

    public function testRegenerateDelegatesToDocumentManagerAndReturnsNewId(): void
    {
        $this->documentManager->expects(self::once())->method('regenerate')->with(10)->willReturn(99);

        self::assertSame(99, $this->service->regenerate(10));
    }
}
