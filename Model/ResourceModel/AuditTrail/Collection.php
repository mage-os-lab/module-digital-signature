<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\ResourceModel\AuditTrail;

use MageOS\DigitalSignature\Model\AuditTrail;
use MageOS\DigitalSignature\Model\ResourceModel\AuditTrail as AuditTrailResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'document_id';
    protected $_eventPrefix = 'digitalsignature_audit_trail_collection';

    protected function _construct(): void
    {
        $this->_init(AuditTrail::class, AuditTrailResource::class);
    }
}
