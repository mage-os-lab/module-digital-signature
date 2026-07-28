<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Cron;

use MageOS\DigitalSignature\Model\Queue\Publisher;
use MageOS\DigitalSignature\Model\ResourceModel\WebhookDelivery as WebhookDeliveryResource;
use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Re-queues "pending" webhook deliveries whose next_attempt_at has come due
 * (temporary Consumer failures). Same role as Cron\Reconcile for documents,
 * applied to webhook deliveries.
 */
class WebhookRetry
{
    private const XML_PATH_BATCH_LIMIT = 'digital_signature/webhooks/batch_limit';

    public function __construct(
        private readonly WebhookDeliveryResource $deliveryResource,
        private readonly Publisher $publisher,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function execute(): void
    {
        $limit = max(1, (int)$this->scopeConfig->getValue(self::XML_PATH_BATCH_LIMIT));
        foreach ($this->deliveryResource->getDuePending($limit) as $row) {
            $this->publisher->publishWebhookDispatch((int)$row['delivery_id']);
        }
    }
}
