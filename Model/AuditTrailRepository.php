<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model;

use MageOS\DigitalSignature\Api\AuditTrailRepositoryInterface;
use MageOS\DigitalSignature\Api\Data\AuditTrailInterface;
use MageOS\DigitalSignature\Model\ResourceModel\AuditTrail as AuditTrailResource;
use Magento\Framework\Exception\CouldNotSaveException;

class AuditTrailRepository implements AuditTrailRepositoryInterface
{
    public function __construct(
        private readonly AuditTrailResource $resource,
        private readonly AuditTrailFactory $auditTrailFactory
    ) {
    }

    public function save(AuditTrailInterface $auditTrail): AuditTrailInterface
    {
        try {
            $this->resource->save($auditTrail);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('Unable to save the audit trail: %1', $e->getMessage()), $e);
        }

        return $auditTrail;
    }

    public function getByDocumentId(int $documentId): ?AuditTrailInterface
    {
        $auditTrail = $this->auditTrailFactory->create();
        $this->resource->load($auditTrail, $documentId);

        return $auditTrail->getDocumentId() === null ? null : $auditTrail;
    }
}
