<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Cron;

use MageOS\DigitalSignature\Api\Data\DossierExportInterface as Export;
use MageOS\DigitalSignature\Api\DossierExportRepositoryInterface;
use MageOS\DigitalSignature\Model\Dossier\DossierExportNotifier;
use MageOS\DigitalSignature\Model\Dossier\DossierExportStateMachine;
use MageOS\DigitalSignature\Model\Dossier\DossierFileLocator;
use MageOS\DigitalSignature\Model\ResourceModel\DossierExport\CollectionFactory;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * Hourly housekeeping of dossier exports:
 * - ready exports past expires_at: file deleted, then expired;
 * - processing exports whose heartbeat stopped for STALE_HEARTBEAT_MINUTES:
 *   failed, but only if the conditional update still matches (a live
 *   consumer's heartbeat in the meantime wins), then file deleted + notice.
 *   The criterion is lack of progress, not duration: a large export that is
 *   alive heartbeats after every document and is never touched;
 * - failed/expired rows older than TERMINAL_ROW_DAYS: deleted;
 * - tmp- files of synchronous exports left by killed requests: deleted.
 */
class DossierExportCleanup
{
    public const STALE_HEARTBEAT_MINUTES = 15;
    public const TERMINAL_ROW_DAYS = 30;
    public const TEMPORARY_FILE_HOURS = 24;

    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly DossierExportRepositoryInterface $repository,
        private readonly DossierExportStateMachine $stateMachine,
        private readonly DossierFileLocator $fileLocator,
        private readonly DossierExportNotifier $notifier,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $now = $this->dateTime->gmtTimestamp();

        $this->expireReadyExports();
        $this->failInterruptedExports(gmdate('Y-m-d H:i:s', $now - self::STALE_HEARTBEAT_MINUTES * 60));
        $this->deleteOldTerminalRows(gmdate('Y-m-d H:i:s', $now - self::TERMINAL_ROW_DAYS * 86400));
        $this->fileLocator->deleteTemporaryFilesOlderThan($now - self::TEMPORARY_FILE_HOURS * 3600);
    }

    private function expireReadyExports(): void
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(Export::STATUS, Export::STATUS_READY);
        $collection->addFieldToFilter(Export::EXPIRES_AT, ['lt' => $this->stateMachine->now()]);

        foreach ($collection as $export) {
            $id = (int)$export->getExportId();
            try {
                // File first: if it cannot be deleted the row stays ready and is retried next hour
                if ($export->getFilePath() !== null) {
                    $this->fileLocator->delete($export->getFilePath());
                }
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    'DigitalSignature: expired dossier export #%d, file not deleted: %s',
                    $id,
                    $e->getMessage()
                ));
                continue;
            }
            $this->stateMachine->markExpired($id);
        }
    }

    private function failInterruptedExports(string $staleBefore): void
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(Export::STATUS, Export::STATUS_PROCESSING);
        $collection->addFieldToFilter(Export::HEARTBEAT_AT, ['lt' => $staleBefore]);

        foreach ($collection as $export) {
            $id = (int)$export->getExportId();
            if (!$this->stateMachine->markInterrupted($id, $staleBefore)) {
                continue; // the consumer heartbeated in the meantime: it is alive
            }
            if ($export->getFilePath() !== null) {
                try {
                    $this->fileLocator->delete($export->getFilePath());
                } catch (\Throwable $e) {
                    $this->logger->error(sprintf(
                        'DigitalSignature: interrupted dossier export #%d, partial file not deleted: %s',
                        $id,
                        $e->getMessage()
                    ));
                }
            }
            $this->notifier->failed($id, DossierExportStateMachine::INTERRUPTED_ERROR);
        }
    }

    private function deleteOldTerminalRows(string $completedBefore): void
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(Export::STATUS, ['in' => [Export::STATUS_FAILED, Export::STATUS_EXPIRED]]);
        $collection->addFieldToFilter(Export::COMPLETED_AT, ['lt' => $completedBefore]);

        foreach ($collection as $export) {
            try {
                $this->repository->delete($export);
            } catch (\Throwable $e) {
                $this->logger->error('DigitalSignature: old dossier export row not deleted: ' . $e->getMessage());
            }
        }
    }
}
