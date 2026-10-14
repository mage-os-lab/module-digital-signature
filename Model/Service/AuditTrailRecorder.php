<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Service;

use MageOS\DigitalSignature\Api\AuditTrailRepositoryInterface;
use MageOS\DigitalSignature\Api\Data\AuditTrailInterface;
use MageOS\DigitalSignature\Api\Data\DocumentInterface;
use MageOS\DigitalSignature\Model\AuditTrailFactory;
use Psr\Log\LoggerInterface;

/**
 * Records our own integrity hash of the document, before and after signing.
 * Never throws: a failure here must never break the main signing flow.
 */
class AuditTrailRecorder
{
    public function __construct(
        private readonly AuditTrailComplianceGate $gate,
        private readonly AuditTrailRepositoryInterface $auditTrailRepository,
        private readonly AuditTrailFactory $auditTrailFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    public function recordHashBeforeSigning(DocumentInterface $document, string $pdfContent): void
    {
        $this->record($document, fn (AuditTrailInterface $auditTrail) => $auditTrail->setHashBeforeSigning(
            hash('sha256', $pdfContent)
        ));
    }

    public function recordHashAfterSigning(DocumentInterface $document, string $pdfContent): void
    {
        $this->record($document, fn (AuditTrailInterface $auditTrail) => $auditTrail->setHashAfterSigning(
            hash('sha256', $pdfContent)
        ));
    }

    private function record(DocumentInterface $document, callable $mutate): void
    {
        $storeId = (int)$document->getStoreId();
        if (!$this->gate->isDataCollectionEnabled($storeId)) {
            return;
        }

        $documentId = (int)$document->getDocumentId();
        try {
            $auditTrail = $this->auditTrailRepository->getByDocumentId($documentId);
            if ($auditTrail === null) {
                $auditTrail = $this->auditTrailFactory->create();
                $auditTrail->isObjectNew(true);
                $auditTrail->setDocumentId($documentId);
                $auditTrail->setProviderCode($document->getProviderCode());
                $auditTrail->setFetchStatus(AuditTrailInterface::FETCH_STATUS_PENDING);
            }
            $mutate($auditTrail);
            $this->auditTrailRepository->save($auditTrail);
        } catch (\Exception $e) {
            $this->logger->warning(
                sprintf('DigitalSignature: audit trail recording failed for document %d: %s', $documentId, $e->getMessage())
            );
        }
    }
}
