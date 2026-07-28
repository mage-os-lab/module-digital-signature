<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\ResourceModel\WebhookSubscription;

use MageOS\DigitalSignature\Model\ResourceModel\WebhookSubscription as WebhookSubscriptionResource;
use MageOS\DigitalSignature\Model\WebhookSubscription as WebhookSubscriptionModel;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'subscription_id';
    protected $_eventPrefix = 'digitalsignature_webhook_subscription_collection';
    protected $_eventObject = 'webhook_subscription_collection';

    protected function _construct(): void
    {
        $this->_init(WebhookSubscriptionModel::class, WebhookSubscriptionResource::class);
    }
}
