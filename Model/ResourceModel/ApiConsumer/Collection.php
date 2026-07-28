<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\ResourceModel\ApiConsumer;

use MageOS\DigitalSignature\Model\ApiConsumer;
use MageOS\DigitalSignature\Model\ResourceModel\ApiConsumer as ApiConsumerResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'consumer_id';
    protected $_eventPrefix = 'digitalsignature_api_consumer_collection';

    protected function _construct(): void
    {
        $this->_init(ApiConsumer::class, ApiConsumerResource::class);
    }
}
