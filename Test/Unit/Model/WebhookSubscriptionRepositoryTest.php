<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model;

use MageOS\DigitalSignature\Model\ResourceModel\WebhookSubscription as WebhookSubscriptionResource;
use MageOS\DigitalSignature\Model\WebhookSubscription;
use MageOS\DigitalSignature\Model\WebhookSubscriptionFactory;
use MageOS\DigitalSignature\Model\WebhookSubscriptionRepository;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class WebhookSubscriptionRepositoryTest extends TestCase
{
    private WebhookSubscriptionResource&MockObject $resource;
    private WebhookSubscriptionFactory&MockObject $factory;
    private WebhookSubscriptionRepository $repository;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(WebhookSubscriptionResource::class);
        $this->factory = $this->createMock(WebhookSubscriptionFactory::class);
        $this->repository = new WebhookSubscriptionRepository(
            $this->resource,
            $this->factory
        );
    }

    public function testSaveCallsResourceSaveAndReturnsSubscription(): void
    {
        $subscription = $this->createMock(WebhookSubscription::class);
        $this->resource->expects(self::once())->method('save')->with($subscription);

        $result = $this->repository->save($subscription);
        self::assertSame($subscription, $result);
    }

    public function testSaveThrowsCouldNotSaveExceptionOnFailure(): void
    {
        $subscription = $this->createMock(WebhookSubscription::class);
        $this->resource->method('save')->willThrowException(new \Exception('DB error'));

        $this->expectException(CouldNotSaveException::class);
        $this->repository->save($subscription);
    }

    public function testGetByIdLoadsAndReturnsSubscription(): void
    {
        $subscription = $this->createMock(WebhookSubscription::class);
        $subscription->method('getSubscriptionId')->willReturn(5);

        $this->factory->method('create')->willReturn($subscription);
        $this->resource->expects(self::once())->method('load')->with($subscription, 5);

        $result = $this->repository->getById(5);
        self::assertSame($subscription, $result);
    }

    public function testGetByIdThrowsNoSuchEntityExceptionIfNotFound(): void
    {
        $subscription = $this->createMock(WebhookSubscription::class);
        $subscription->method('getSubscriptionId')->willReturn(null);

        $this->factory->method('create')->willReturn($subscription);
        $this->resource->expects(self::once())->method('load')->with($subscription, 999);

        $this->expectException(NoSuchEntityException::class);
        $this->repository->getById(999);
    }

    public function testDeleteCallsResourceDelete(): void
    {
        $subscription = $this->createMock(WebhookSubscription::class);
        $this->resource->expects(self::once())->method('delete')->with($subscription);

        $this->repository->delete($subscription);
    }

    public function testDeleteThrowsCouldNotDeleteExceptionOnFailure(): void
    {
        $subscription = $this->createMock(WebhookSubscription::class);
        $this->resource->method('delete')->willThrowException(new \Exception('DB error'));

        $this->expectException(CouldNotDeleteException::class);
        $this->repository->delete($subscription);
    }
}
