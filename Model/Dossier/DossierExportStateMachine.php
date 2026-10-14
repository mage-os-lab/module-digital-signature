<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Dossier;

use MageOS\DigitalSignature\Api\Data\DossierExportInterface as Export;
use MageOS\DigitalSignature\Model\ResourceModel\DossierExport;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * The only writer of a dossier export's state. Every transition is a single
 * conditional UPDATE (WHERE status = <expected>), and succeeds only when it
 * changed exactly one row: the consumer, the cleanup cron and an admin delete
 * can race, but only one of them ever wins a transition.
 */
class DossierExportStateMachine
{
    public const INTERRUPTED_ERROR = 'Interrupted: the export stopped making progress.';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly DateTime $dateTime
    ) {
    }

    public function claim(int $exportId, string $filePath): bool
    {
        $now = $this->now();

        return $this->transition($exportId, Export::STATUS_QUEUED, [
            Export::STATUS => Export::STATUS_PROCESSING,
            Export::FILE_PATH => $filePath,
            Export::STARTED_AT => $now,
            Export::HEARTBEAT_AT => $now,
            Export::PROCESSED_DOCUMENTS => 0,
        ]);
    }

    /**
     * processed_documents strictly increases, so the row always changes even
     * when two heartbeats fall in the same second (MySQL reports changed rows).
     */
    public function heartbeat(int $exportId, int $processedDocuments): bool
    {
        return $this->transition($exportId, Export::STATUS_PROCESSING, [
            Export::HEARTBEAT_AT => $this->now(),
            Export::PROCESSED_DOCUMENTS => $processedDocuments,
        ]);
    }

    public function markReady(int $exportId, int $fileSize, int $retentionHours): bool
    {
        $timestamp = $this->dateTime->gmtTimestamp();

        return $this->transition($exportId, Export::STATUS_PROCESSING, [
            Export::STATUS => Export::STATUS_READY,
            Export::FILE_SIZE => $fileSize,
            Export::COMPLETED_AT => gmdate('Y-m-d H:i:s', $timestamp),
            Export::EXPIRES_AT => gmdate('Y-m-d H:i:s', $timestamp + $retentionHours * 3600),
        ]);
    }

    public function markFailed(int $exportId, string $error): bool
    {
        return $this->transition($exportId, Export::STATUS_PROCESSING, [
            Export::STATUS => Export::STATUS_FAILED,
            Export::ERROR => $error,
            Export::COMPLETED_AT => $this->now(),
        ]);
    }

    public function markInterrupted(int $exportId, string $staleBefore): bool
    {
        return $this->transition(
            $exportId,
            Export::STATUS_PROCESSING,
            [
                Export::STATUS => Export::STATUS_FAILED,
                Export::ERROR => self::INTERRUPTED_ERROR,
                Export::COMPLETED_AT => $this->now(),
            ],
            [Export::HEARTBEAT_AT . ' < ?' => $staleBefore]
        );
    }

    public function markExpired(int $exportId): bool
    {
        return $this->transition($exportId, Export::STATUS_READY, [
            Export::STATUS => Export::STATUS_EXPIRED,
            Export::FILE_PATH => null,
        ]);
    }

    public function now(): string
    {
        return gmdate('Y-m-d H:i:s', $this->dateTime->gmtTimestamp());
    }

    private function transition(int $exportId, string $fromStatus, array $bind, array $extraWhere = []): bool
    {
        $where = [Export::EXPORT_ID . ' = ?' => $exportId, Export::STATUS . ' = ?' => $fromStatus] + $extraWhere;
        $affected = $this->resource->getConnection()->update(
            $this->resource->getTableName(DossierExport::MAIN_TABLE),
            $bind,
            $where
        );

        return (int)$affected === 1;
    }
}
