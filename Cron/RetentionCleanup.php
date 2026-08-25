<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Cron;

use MageOS\DigitalSignature\Api\Data\DocumentInterface;
use MageOS\DigitalSignature\Api\DocumentRepositoryInterface;
use MageOS\DigitalSignature\Api\DocumentStorageInterface;
use MageOS\DigitalSignature\Model\Document\Status;
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
 */
class RetentionCleanup
{
    private const XML_PATH_ENABLED = 'digital_signature/retention/enabled';
    private const XML_PATH_RETENTION_DAYS = 'digital_signature/retention/retention_days';
    private const XML_PATH_BATCH_LIMIT = 'digital_signature/retention/batch_limit';

    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly DocumentRepositoryInterface $documentRepository,
        private readonly DocumentStorageInterface $storage,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED)) {
            return;
        }

        $retentionDays = max(1, (int)$this->scopeConfig->getValue(self::XML_PATH_RETENTION_DAYS));
        $limit = max(1, (int)$this->scopeConfig->getValue(self::XML_PATH_BATCH_LIMIT));

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
            $this->logger->info(sprintf('DigitalSignature retention: %d documenti purgati.', $purged));
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

    private function deleteFile(?string $path): void
    {
        if ($path === null) {
            return;
        }
        try {
            $this->storage->delete($path);
        } catch (FileSystemException $e) {
            $this->logger->error('DigitalSignature retention: file deletion error: ' . $e->getMessage());
        }
    }
}
