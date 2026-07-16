<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\ResourceModel\Document;

use MageOS\DigitalSignature\Model\Document;
use MageOS\DigitalSignature\Model\ResourceModel\Document as DocumentResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'document_id';
    protected $_eventPrefix = 'digitalsignature_document_collection';

    protected function _construct(): void
    {
        $this->_init(Document::class, DocumentResource::class);
    }
}
