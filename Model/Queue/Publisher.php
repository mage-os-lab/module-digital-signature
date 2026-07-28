<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Queue;

use Magento\Framework\MessageQueue\PublisherInterface;

/**
 * Publishes document jobs. Messages contain ONLY the id (design decision:
 * minimal payload, the status is always reloaded from the DB).
 */
class Publisher
{
    public const TOPIC_PROCESS = 'digitalsignature.document.process';
    public const TOPIC_REFRESH = 'digitalsignature.document.refresh';
    public const TOPIC_WEBHOOK_DISPATCH = 'digitalsignature.webhook.dispatch';

    public function __construct(private readonly PublisherInterface $publisher)
    {
    }

    public function publishProcess(int $documentId): void
    {
        $this->publisher->publish(self::TOPIC_PROCESS, (string)$documentId);
    }

    public function publishRefresh(int $documentId): void
    {
        $this->publisher->publish(self::TOPIC_REFRESH, (string)$documentId);
    }

    public function publishWebhookDispatch(int $deliveryId): void
    {
        $this->publisher->publish(self::TOPIC_WEBHOOK_DISPATCH, (string)$deliveryId);
    }
}
