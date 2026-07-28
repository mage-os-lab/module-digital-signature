<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model;

use MageOS\DigitalSignature\Api\Data\WebhookSubscriptionInterface;
use MageOS\DigitalSignature\Api\WebhookSubscriptionRepositoryInterface;
use MageOS\DigitalSignature\Model\ResourceModel\WebhookSubscription as WebhookSubscriptionResource;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;

class WebhookSubscriptionRepository implements WebhookSubscriptionRepositoryInterface
{
    public function __construct(
        private readonly WebhookSubscriptionResource $resource,
        private readonly WebhookSubscriptionFactory $webhookSubscriptionFactory
    ) {
    }

    public function save(WebhookSubscriptionInterface $subscription): WebhookSubscriptionInterface
    {
        try {
            $this->resource->save($subscription);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(
                __('Unable to save Webhook subscription: %1', $e->getMessage()),
                $e
            );
        }

        return $subscription;
    }

    public function getById(int $subscriptionId): WebhookSubscriptionInterface
    {
        $subscription = $this->webhookSubscriptionFactory->create();
        $this->resource->load($subscription, $subscriptionId);
        if (!$subscription->getSubscriptionId()) {
            throw new NoSuchEntityException(__('Webhook subscription with id "%1" does not exist.', $subscriptionId));
        }

        return $subscription;
    }

    public function delete(WebhookSubscriptionInterface $subscription): void
    {
        try {
            $this->resource->delete($subscription);
        } catch (\Exception $e) {
            throw new CouldNotDeleteException(
                __('Unable to delete Webhook subscription: %1', $e->getMessage()),
                $e
            );
        }
    }
}
