<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Observer;

use MageOS\DigitalSignature\Api\Data\DocumentInterface;
use MageOS\DigitalSignature\Model\ResourceModel\WebhookDelivery as WebhookDeliveryResource;
use MageOS\DigitalSignature\Model\ResourceModel\WebhookSubscription as WebhookSubscriptionResource;
use MageOS\DigitalSignature\Model\Queue\Publisher;
use MageOS\DigitalSignature\Model\Webhook\PayloadBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * On every document status change (event dispatched by
 * DocumentRepository::addLog), creates a delivery row for each webhook
 * subscription active on the document's store and queues it.
 * No HTTP call here: only DB writes + queuing, so as not to slow down
 * the synchronous flow that generates the event.
 */
class DispatchWebhook implements ObserverInterface
{
    private const XML_PATH_ENABLED = 'digital_signature/webhooks/enabled';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WebhookSubscriptionResource $subscriptionResource,
        private readonly WebhookDeliveryResource $deliveryResource,
        private readonly PayloadBuilder $payloadBuilder,
        private readonly Publisher $publisher
    ) {
    }

    public function execute(Observer $observer): void
    {
        /** @var DocumentInterface $document */
        $document = $observer->getData('document');
        $storeId = $document->getStoreId();
        if (!$this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, 'store', $storeId)) {
            return;
        }

        $statusFrom = $observer->getData('status_from');
        $statusTo = (string)$observer->getData('status_to');

        foreach ($this->subscriptionResource->getActiveForStore($storeId) as $subscription) {
            // The row must be created before the payload: the payload includes the
            // delivery_id (dedup on the receiving side), which exists only after
            // the insert. Each subscription therefore has its own payload.
            $deliveryId = $this->deliveryResource->create(
                (int)$subscription['subscription_id'],
                (int)$document->getDocumentId(),
                'status_changed',
                ''
            );
            $this->deliveryResource->setPayload(
                $deliveryId,
                $this->payloadBuilder->build($document, $statusFrom, $statusTo, $deliveryId)
            );
            $this->publisher->publishWebhookDispatch($deliveryId);
        }
    }
}
