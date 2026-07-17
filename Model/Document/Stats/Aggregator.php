<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Document\Stats;

use MageOS\DigitalSignature\Model\ResourceModel\Document\CollectionFactory;
use Magento\Framework\App\ResourceConnection;

class Aggregator
{
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function getSummary(?\DateTimeInterface $from, \DateTimeInterface $to): Summary
    {
        $connection = $this->resourceConnection->getConnection();

        $whereConditions = [];
        $binds = [];

        if ($from !== null) {
            $whereConditions[] = 'created_at >= :from';
            $binds['from'] = $from->format('Y-m-d H:i:s');
        }
        $whereConditions[] = 'created_at <= :to';
        $binds['to'] = $to->format('Y-m-d H:i:s');

        $whereSql = implode(' AND ', $whereConditions);

        $documentTable = $this->resourceConnection->getTableName('mageos_digitalsignature_document');
        $query = "SELECT status, is_active, COUNT(*) as cnt FROM {$documentTable} WHERE {$whereSql} GROUP BY status, is_active";

        $rows = $connection->fetchAll($query, $binds);

        $totalGenerated = 0;
        $signedCount = 0;
        $expiredDeclinedCount = 0;
        $pendingCount = 0;

        foreach ($rows as $row) {
            $cnt = (int)$row['cnt'];
            $status = $row['status'];
            $isActive = (bool)$row['is_active'];

            $totalGenerated += $cnt;

            if ($status === 'signed') {
                $signedCount += $cnt;
            } elseif ($status === 'expired' || $status === 'declined') {
                $expiredDeclinedCount += $cnt;
            } elseif ($status === 'sent' && $isActive) {
                $pendingCount += $cnt;
            }
        }

        $signedPercentage = $totalGenerated > 0 ? round(($signedCount / $totalGenerated) * 100, 1) : 0.0;
        $expiredDeclinedPercentage = $totalGenerated > 0 ? round(($expiredDeclinedCount / $totalGenerated) * 100, 1) : 0.0;

        $logTable = $this->resourceConnection->getTableName('mageos_digitalsignature_document_log');
        $whereSqlWithAlias = str_replace('created_at', 'd.created_at', $whereSql);
        $avgQuery = "
            SELECT AVG(TIMESTAMPDIFF(SECOND, log_sent.created_at, log_signed.created_at)) / 86400.0 as avg_days
            FROM {$documentTable} d
            JOIN {$logTable} log_sent
                ON d.document_id = log_sent.document_id
                AND log_sent.event = 'status_change'
                AND log_sent.status_to = 'sent'
            JOIN {$logTable} log_signed
                ON d.document_id = log_signed.document_id
                AND log_signed.event = 'status_change'
                AND log_signed.status_to = 'signed'
            WHERE {$whereSqlWithAlias}
        ";

        $avgDays = (float)$connection->fetchOne($avgQuery, $binds);
        if ($avgDays < 0) {
            $avgDays = 0.0;
        }
        $avgDays = round($avgDays, 2);

        return new Summary(
            $totalGenerated,
            $signedCount,
            (float)$signedPercentage,
            $expiredDeclinedCount,
            (float)$expiredDeclinedPercentage,
            $pendingCount,
            (float)$avgDays
        );
    }
}
