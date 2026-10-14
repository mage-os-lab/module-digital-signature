<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Cron;

use MageOS\DigitalSignature\Api\AuditTrailRepositoryInterface;
use MageOS\DigitalSignature\Api\Data\AuditTrailInterface;
use MageOS\DigitalSignature\Api\Data\DocumentInterface;
use MageOS\DigitalSignature\Api\DocumentRepositoryInterface;
use MageOS\DigitalSignature\Api\DocumentStorageInterface;
use MageOS\DigitalSignature\Model\Document\Status;
use MageOS\DigitalSignature\Model\ResourceModel\AuditTrail\CollectionFactory as AuditTrailCollectionFactory;
use MageOS\DigitalSignature\Model\ResourceModel\Document\CollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * GDPR retention (analysis §12, §13-bis): periodically deletes PDFs and
 * personal data of completed documents (final states) past the configured
 * retention period. The record and status history remain (tax/legal
 * audit), but without PDF, signer email/phone, or raw payloads in the
 * event log. Disabled by default: explicit opt-in because it is
 * destructive.
 *
 * The audit trail's personal data is purged on its own schedule
 * (digital_signature/audit_trail/retention_days), whether or not the
 * document retention above is enabled: that period is set explicitly when
 * the merchant opts in to audit trail collection.
 */
class RetentionCleanup
{
    private const XML_PATH_ENABLED = 'digital_signature/retention/enabled';
    private const XML_PATH_RETENTION_DAYS = 'digital_signature/retention/retention_days';
    private const XML_PATH_BATCH_LIMIT = 'digital_signature/retention/batch_limit';
    private const XML_PATH_AUDIT_TRAIL_RETENTION_DAYS = 'digital_signature/audit_trail/retention_days';

    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly DocumentRepositoryInterface $documentRepository,
        private readonly DocumentStorageInterface $storage,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger,
        private readonly AuditTrailCollectionFactory $auditTrailCollectionFactory,
        private readonly AuditTrailRepositoryInterface $auditTrailRepository
    ) {
    }

    public function execute(): void
    {
        $limit = max(1, (int)$this->scopeConfig->getValue(self::XML_PATH_BATCH_LIMIT));

        if ($this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED)) {
            $this->purgeDocuments($limit);
        }

        $this->purgeAuditTrails($limit);
    }

    private function purgeDocuments(int $limit): void
    {
        $retentionDays = max(1, (int)$this->scopeConfig->getValue(self::XML_PATH_RETENTION_DAYS));

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status', ['in' => Status::FINAL_STATES]);
        $collection->addFieldToFilter('purged_at', ['null' => true]);
        $collection->addFieldToFilter(
            'updated_at',
            ['lt' => date('Y-m-d H:i:s', $this->dateTime->gmtTimestamp() - $retentionDays * 86400)]
        );
        $collection->setPageSize($limit);
        $collection->setOrder('updated_at', 'ASC');

        $purged = 0;
        foreach ($collection as $document) {
            $this->purge($document);
            $purged++;
        }

        if ($purged) {
            $this->logger->info(sprintf('DigitalSignature retention: %d documents purged.', $purged));
        }
    }

    /**
     * The audit trail's retention period (digital_signature/audit_trail/retention_days)
     * is independent from the document's own retention_days (Fase 0 design
     * decision: proof of signature may legitimately need to be kept for a
     * different period than the signer's PDF/contact details). Only the
     * personal-data fields of the audit trail row are erased here -
     * certificate_path (the certificate PDF, which contains the signer's
     * IP/identity), signer_ip and authentication_method. hash_before_signing,
     * hash_after_signing, transaction_id and fetch_status are the
     * eIDAS-relevant proof data (not personal data) and are kept.
     */
    private function purgeAuditTrails(int $limit): void
    {
        $retentionDays = (int)$this->scopeConfig->getValue(self::XML_PATH_AUDIT_TRAIL_RETENTION_DAYS);
        if ($retentionDays <= 0) {
            // Not configured (or explicitly disabled by an admin setting it
            // to 0): nothing to purge on this independent schedule.
            return;
        }

        $collection = $this->auditTrailCollectionFactory->create();
        $collection->addFieldToFilter('certificate_path', ['notnull' => true]);
        $collection->addFieldToFilter('fetched_at', ['notnull' => true]);
        $collection->addFieldToFilter(
            'fetched_at',
            ['lt' => date('Y-m-d H:i:s', $this->dateTime->gmtTimestamp() - $retentionDays * 86400)]
        );
        $collection->setPageSize($limit);

        $purged = 0;
        foreach ($collection as $auditTrail) {
            $this->purgeAuditTrail($auditTrail);
            $purged++;
        }

        if ($purged) {
            $this->logger->info(sprintf('DigitalSignature retention: %d audit trail certificates purged.', $purged));
        }
    }

    private function purgeAuditTrail(AuditTrailInterface $auditTrail): void
    {
        // If the file cannot be deleted the path is kept, so the row still
        // matches on the next run and the deletion is retried.
        if ($this->deleteFile($auditTrail->getCertificatePath())) {
            $auditTrail->setCertificatePath(null);
        }
        $auditTrail->setSignerIp(null);
        $auditTrail->setAuthenticationMethod(null);

        try {
            $this->auditTrailRepository->save($auditTrail);
        } catch (\Exception $e) {
            $this->logger->error('DigitalSignature retention: audit trail purge save error: ' . $e->getMessage());
        }
    }

    private function purge(DocumentInterface $document): void
    {
        $this->deleteFile($document->getPdfPath());
        $this->deleteFile($document->getSignedPdfPath());

        $document->setPdfPath(null);
        $document->setSignedPdfPath(null);
        $document->setSignerEmail(null);
        $document->setSignerPhone(null);
        $document->setPurgedAt(date('Y-m-d H:i:s', $this->dateTime->gmtTimestamp()));

        $this->documentRepository->save($document);
        $this->documentRepository->purgeLogPayloads($document);
        $this->documentRepository->addLog(
            $document,
            'retention_purge',
            null,
            null,
            (string)__('PDF and personal data deleted at the end of the retention period.')
        );
    }

    private function deleteFile(?string $path): bool
    {
        if ($path === null) {
            return true;
        }
        try {
            $this->storage->delete($path);
        } catch (FileSystemException $e) {
            $this->logger->error('DigitalSignature retention: file deletion error: ' . $e->getMessage());

            return false;
        }

        return true;
    }
}
