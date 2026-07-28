<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Api;

use MageOS\DigitalSignature\Api\Data\WebhookSubscriptionInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;

interface WebhookSubscriptionRepositoryInterface
{
    /**
     * @throws CouldNotSaveException
     */
    public function save(WebhookSubscriptionInterface $subscription): WebhookSubscriptionInterface;

    /**
     * @throws NoSuchEntityException
     */
    public function getById(int $subscriptionId): WebhookSubscriptionInterface;

    /**
     * @throws CouldNotDeleteException
     */
    public function delete(WebhookSubscriptionInterface $subscription): void;
}
