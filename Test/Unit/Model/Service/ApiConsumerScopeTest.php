<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Service;

use MageOS\DigitalSignature\Api\ApiConsumerRepositoryInterface;
use MageOS\DigitalSignature\Api\Data\ApiConsumerInterface;
use MageOS\DigitalSignature\Model\Service\ApiConsumerScope;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ApiConsumerScopeTest extends TestCase
{
    private UserContextInterface&MockObject $userContext;
    private ApiConsumerRepositoryInterface&MockObject $consumerRepository;
    private OrderRepositoryInterface&MockObject $orderRepository;
    private ApiConsumerScope $scope;

    protected function setUp(): void
    {
        $this->userContext = $this->createMock(UserContextInterface::class);
        $this->consumerRepository = $this->createMock(ApiConsumerRepositoryInterface::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->scope = new ApiConsumerScope($this->userContext, $this->consumerRepository, $this->orderRepository);
    }

    public function testNonIntegrationCallerIsUnrestricted(): void
    {
        $this->userContext->method('getUserType')->willReturn(UserContextInterface::USER_TYPE_ADMIN);

        self::assertNull($this->scope->getAllowedStoreIds());
        self::assertTrue($this->scope->isStoreAllowed(999));
    }

    public function testIntegrationWithoutMappingIsFailClosed(): void
    {
        $this->userContext->method('getUserType')->willReturn(UserContextInterface::USER_TYPE_INTEGRATION);
        $this->userContext->method('getUserId')->willReturn(5);
        $this->consumerRepository->method('getByIntegrationId')->with(5)
            ->willThrowException(new NoSuchEntityException(__('non mappata')));

        self::assertSame([], $this->scope->getAllowedStoreIds());
        self::assertFalse($this->scope->isStoreAllowed(1));
    }

    public function testDisabledIntegrationIsFailClosed(): void
    {
        $this->userContext->method('getUserType')->willReturn(UserContextInterface::USER_TYPE_INTEGRATION);
        $this->userContext->method('getUserId')->willReturn(5);
        $consumer = $this->createMock(ApiConsumerInterface::class);
        $consumer->method('getEnabled')->willReturn(false);
        $this->consumerRepository->method('getByIntegrationId')->willReturn($consumer);

        self::assertSame([], $this->scope->getAllowedStoreIds());
    }

    public function testEnabledIntegrationReturnsMappedStores(): void
    {
        $this->userContext->method('getUserType')->willReturn(UserContextInterface::USER_TYPE_INTEGRATION);
        $this->userContext->method('getUserId')->willReturn(5);
        $consumer = $this->createMock(ApiConsumerInterface::class);
        $consumer->method('getEnabled')->willReturn(true);
        $consumer->method('getConsumerId')->willReturn(7);
        $this->consumerRepository->method('getByIntegrationId')->willReturn($consumer);
        $this->consumerRepository->method('getStoreIds')->with(7)->willReturn([1, 2]);

        self::assertSame([1, 2], $this->scope->getAllowedStoreIds());
        self::assertTrue($this->scope->isStoreAllowed(1));
        self::assertFalse($this->scope->isStoreAllowed(3));
    }

    public function testAssertOrderInScopeThrowsWhenStoreNotAllowed(): void
    {
        $this->userContext->method('getUserType')->willReturn(UserContextInterface::USER_TYPE_INTEGRATION);
        $this->userContext->method('getUserId')->willReturn(5);
        $consumer = $this->createMock(ApiConsumerInterface::class);
        $consumer->method('getEnabled')->willReturn(true);
        $consumer->method('getConsumerId')->willReturn(7);
        $this->consumerRepository->method('getByIntegrationId')->willReturn($consumer);
        $this->consumerRepository->method('getStoreIds')->willReturn([1]);

        $order = $this->createMock(OrderInterface::class);
        $order->method('getStoreId')->willReturn(2);
        $this->orderRepository->method('get')->with(42)->willReturn($order);

        $this->expectException(NoSuchEntityException::class);

        $this->scope->assertOrderInScope(42);
    }

    public function testAssertOrderInScopePassesWhenStoreAllowed(): void
    {
        $this->userContext->method('getUserType')->willReturn(UserContextInterface::USER_TYPE_ADMIN);
        $order = $this->createMock(OrderInterface::class);
        $order->method('getStoreId')->willReturn(2);
        $this->orderRepository->method('get')->with(42)->willReturn($order);

        $this->scope->assertOrderInScope(42);
        $this->addToAssertionCount(1);
    }
}
