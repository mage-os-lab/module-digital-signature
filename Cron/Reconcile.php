<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Cron;

use MageOS\DigitalSignature\Api\Data\AuditTrailInterface;
use MageOS\DigitalSignature\Model\Document\Status;
use MageOS\DigitalSignature\Model\Queue\Publisher;
use MageOS\DigitalSignature\Model\ResourceModel\AuditTrail;
use MageOS\DigitalSignature\Model\ResourceModel\Document\CollectionFactory;
use MageOS\DigitalSignature\Model\Service\AuditTrailComplianceGate;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Periodic reconciliation (analysis §5):
 * - "sent" documents stuck for too long → status refresh (covers missed callbacks);
 * - pending/generated documents stuck with remaining retries → re-queued.
 * The cap per run is the throttling toward the provider decided in the analysis.
 */
class Reconcile
{
    private const XML_PATH_BATCH_LIMIT = 'digital_signature/general/reconcile_batch_limit';
    private const XML_PATH_MAX_RETRIES = 'digital_signature/general/max_retries';

    private const STALE_SENT_MINUTES = 15;
    private const STALE_PROCESS_MINUTES = 10;
    private const STALE_AUDIT_TRAIL_MINUTES = 10;

    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly Publisher $publisher,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger,
        private readonly StoreManagerInterface $storeManager,
        private readonly AuditTrailComplianceGate $complianceGate
    ) {
    }

    public function execute(): void
    {
        $limit = max(1, (int)$this->scopeConfig->getValue(self::XML_PATH_BATCH_LIMIT));

        $refreshed = $this->dispatch(
            [Status::SENT],
            self::STALE_SENT_MINUTES,
            $limit,
            fn (int $id) => $this->publisher->publishRefresh($id)
        );

        $maxRetries = max(1, (int)$this->scopeConfig->getValue(self::XML_PATH_MAX_RETRIES));
        $retried = $this->dispatch(
            [Status::PENDING, Status::GENERATED],
            self::STALE_PROCESS_MINUTES,
            $limit,
            fn (int $id) => $this->publisher->publishProcess($id),
            $maxRetries
        );

        $auditTrailQueued = $this->dispatchAuditTrailFetch($limit, $maxRetries);

        if ($refreshed || $retried || $auditTrailQueued) {
            $this->logger->info(
                sprintf(
                    'DigitalSignature reconcile: %d refresh, %d retry, %d audit trail fetch queued',
                    $refreshed,
                    $retried,
                    $auditTrailQueued
                )
            );
        }
    }

    /**
     * Signed documents whose audit trail row is still pending (or failed
     * under the retry cap) are queued for AuditTrailFetchConsumer.
     *
     * Only stores that currently collect audit trail data are considered, and
     * only documents that already have a row: AuditTrailRecorder creates it
     * when the document is sent with collection on, so documents signed
     * before the opt-in (or in stores that opted out) are never queued only
     * to be skipped by the consumer and picked again on every run.
     *
     * The status condition is built as a single where() call with the OR
     * fully inlined: Magento's collection AND-s together separate where()
     * calls, which would silently break the
     *   (still pending) OR (errored and still under the retry cap)
     * logic if split across two calls.
     *
     * Staleness is measured on the audit trail row, oldest first, and the
     * rows of the published documents are touched: a document queued but not
     * yet consumed (consumer lag/downtime), or one that just failed with a
     * retryable error, waits STALE_AUDIT_TRAIL_MINUTES before being queued
     * again instead of being re-published on every cron run. purged_at IS NULL
     * excludes documents whose personal data RetentionCleanup already erased.
     */
    private function dispatchAuditTrailFetch(int $limit, int $maxRetries): int
    {
        $storeIds = $this->storesCollectingAuditTrail();
        if (!$storeIds) {
            return 0;
        }

        $collection = $this->collectionFactory->create();
        $connection = $collection->getConnection();
        $auditTrailTable = $collection->getTable(AuditTrail::MAIN_TABLE);

        $collection->getSelect()->joinLeft(
            ['audit_trail' => $auditTrailTable],
            'main_table.document_id = audit_trail.document_id',
            ['fetch_status', 'fetch_attempts']
        );
        $collection->addFieldToFilter('main_table.status', Status::SIGNED);
        $collection->addFieldToFilter('main_table.is_active', 1);
        $collection->addFieldToFilter('main_table.purged_at', ['null' => true]);
        $collection->addFieldToFilter('main_table.store_id', ['in' => $storeIds]);
        $collection->addFieldToFilter(
            'audit_trail.updated_at',
            ['lt' => date('Y-m-d H:i:s', time() - self::STALE_AUDIT_TRAIL_MINUTES * 60)]
        );

        $condition = '('
            . $connection->quoteInto('audit_trail.fetch_status = ?', AuditTrailInterface::FETCH_STATUS_PENDING)
            . ') OR ('
            . $connection->quoteInto('audit_trail.fetch_status = ?', AuditTrailInterface::FETCH_STATUS_ERROR)
            . ' AND '
            . $connection->quoteInto('audit_trail.fetch_attempts < ?', $maxRetries)
            . ')';
        $collection->getSelect()->where($condition);

        $collection->setOrder('audit_trail.updated_at', 'ASC');
        $collection->setPageSize($limit);

        $documentIds = [];
        foreach ($collection as $document) {
            $documentId = (int)$document->getId();
            $this->publisher->publishAuditTrailFetch($documentId);
            $documentIds[] = $documentId;
        }

        if ($documentIds) {
            $connection->update(
                $auditTrailTable,
                ['updated_at' => date('Y-m-d H:i:s')],
                ['document_id IN (?)' => $documentIds]
            );
        }

        return count($documentIds);
    }

    /**
     * @return int[]
     */
    private function storesCollectingAuditTrail(): array
    {
        $storeIds = [];
        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int)$store->getId();
            if ($this->complianceGate->isDataCollectionEnabled($storeId)) {
                $storeIds[] = $storeId;
            }
        }

        return $storeIds;
    }

    /**
     * @param string[] $statuses
     */
    private function dispatch(
        array $statuses,
        int $staleMinutes,
        int $limit,
        callable $publish,
        ?int $maxRetries = null
    ): int {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status', ['in' => $statuses]);
        $collection->addFieldToFilter('is_active', 1);
        $collection->addFieldToFilter(
            'updated_at',
            ['lt' => date('Y-m-d H:i:s', time() - $staleMinutes * 60)]
        );
        if ($maxRetries !== null) {
            $collection->addFieldToFilter('retry_count', ['lt' => $maxRetries]);
        }
        $collection->setPageSize($limit);
        $collection->setOrder('updated_at', 'ASC');

        $count = 0;
        foreach ($collection as $document) {
            $publish((int)$document->getId());
            $count++;
        }

        return $count;
    }
}
