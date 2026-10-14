<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Api;

use MageOS\DigitalSignature\Api\Data\AuditTrailInterface;
use Magento\Framework\Exception\CouldNotSaveException;

interface AuditTrailRepositoryInterface
{
    /**
     * @throws CouldNotSaveException
     */
    public function save(AuditTrailInterface $auditTrail): AuditTrailInterface;

    public function getByDocumentId(int $documentId): ?AuditTrailInterface;
}
