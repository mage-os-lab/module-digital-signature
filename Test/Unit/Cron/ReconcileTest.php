<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Cron;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\DigitalSignature\Api\Data\AuditTrailInterface;
use MageOS\DigitalSignature\Cron\Reconcile;
use MageOS\DigitalSignature\Model\Document;
use MageOS\DigitalSignature\Model\Document\Status;
use MageOS\DigitalSignature\Model\Queue\Publisher;
use MageOS\DigitalSignature\Model\ResourceModel\AuditTrail;
use MageOS\DigitalSignature\Model\ResourceModel\Document\Collection;
use MageOS\DigitalSignature\Model\ResourceModel\Document\CollectionFactory;
use MageOS\DigitalSignature\Model\Service\AuditTrailComplianceGate;
use MageOS\DigitalSignature\TestSupport\ModelDoubles;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ReconcileTest extends TestCase
{
    use ModelDoubles;

    private CollectionFactory $collectionFactory;
    private Publisher $publisher;
    private ScopeConfigInterface $scopeConfig;
    private LoggerInterface $logger;
    private StoreManagerInterface $storeManager;
    private AuditTrailComplianceGate $gate;
    private Reconcile $reconcile;

    protected function setUp(): void
    {
        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $this->publisher = $this->createMock(Publisher::class);
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->gate = $this->createMock(AuditTrailComplianceGate::class);

        $this->reconcile = new Reconcile(
            $this->collectionFactory,
            $this->publisher,
            $this->scopeConfig,
            $this->logger,
            $this->storeManager,
            $this->gate
        );
    }

    /**
     * @param array<int, bool> $collectingByStoreId
     */
    private function configureStores(array $collectingByStoreId): void
    {
        $stores = [];
        foreach (array_keys($collectingByStoreId) as $storeId) {
            $store = $this->createStub(StoreInterface::class);
            $store->method('getId')->willReturn($storeId);
            $stores[] = $store;
        }
        $this->storeManager->method('getStores')->willReturn($stores);
        $this->gate->method('isDataCollectionEnabled')->willReturnCallback(
            static fn(int $storeId): bool => $collectingByStoreId[$storeId] ?? false
        );
    }

    private function configureConfig(int $batchLimit, int $maxRetries): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['digital_signature/general/reconcile_batch_limit', 'default', null, (string)$batchLimit],
            ['digital_signature/general/max_retries', 'default', null, (string)$maxRetries],
        ]);
    }

    private function emptyCollection(): Collection
    {
        return $this->newCollection(Collection::class);
    }

    private function collectionWithIds(array $ids): Collection
    {
        $documents = [];
        foreach ($ids as $id) {
            $document = $this->newModel(Document::class);
            $document->setData('id', $id);
            $documents[] = $document;
        }

        return $this->newCollection(Collection::class, $documents);
    }

    public function testQueuesSignedDocumentsMissingCertificate(): void
    {
        $this->configureConfig(50, 3);
        $this->configureStores([1 => true]);
        $this->collectionFactory->method('create')->willReturnOnConsecutiveCalls(
            $this->emptyCollection(), // refresh dispatch
            $this->emptyCollection(), // retry dispatch
            $this->collectionWithIds([11, 12]) // audit trail dispatch
        );
        $this->publisher->expects(self::exactly(2))->method('publishAuditTrailFetch')
            ->willReturnCallback(function (int $id) {
                static $expected = [11, 12];
                self::assertSame(array_shift($expected), $id);
            });

        $this->reconcile->execute();
    }

    public function testRefreshDispatchForwardsIdsToPublishRefresh(): void
    {
        $this->configureConfig(50, 3);
        $this->configureStores([1 => true]);
        $this->collectionFactory->method('create')->willReturnOnConsecutiveCalls(
            $this->collectionWithIds([21, 22]), // refresh dispatch
            $this->emptyCollection(), // retry dispatch
            $this->emptyCollection() // audit trail dispatch
        );
        $this->publisher->expects(self::exactly(2))->method('publishRefresh')
            ->willReturnCallback(function (int $id) {
                static $expected = [21, 22];
                self::assertSame(array_shift($expected), $id);
            });
        $this->publisher->expects(self::never())->method('publishProcess');
        $this->publisher->expects(self::never())->method('publishAuditTrailFetch');

        $this->reconcile->execute();
    }

    public function testRetryDispatchForwardsIdsToPublishProcess(): void
    {
        $this->configureConfig(50, 3);
        $this->configureStores([1 => true]);
        $this->collectionFactory->method('create')->willReturnOnConsecutiveCalls(
            $this->emptyCollection(), // refresh dispatch
            $this->collectionWithIds([31, 32]), // retry dispatch
            $this->emptyCollection() // audit trail dispatch
        );
        $this->publisher->expects(self::exactly(2))->method('publishProcess')
            ->willReturnCallback(function (int $id) {
                static $expected = [31, 32];
                self::assertSame(array_shift($expected), $id);
            });
        $this->publisher->expects(self::never())->method('publishRefresh');
        $this->publisher->expects(self::never())->method('publishAuditTrailFetch');

        $this->reconcile->execute();
    }

    public function testDoesNothingWhenNoDispatchHasWork(): void
    {
        $this->configureConfig(50, 3);
        $this->configureStores([1 => true]);
        $this->collectionFactory->method('create')->willReturnOnConsecutiveCalls(
            $this->emptyCollection(),
            $this->emptyCollection(),
            $this->emptyCollection()
        );
        $this->publisher->expects(self::never())->method('publishAuditTrailFetch');
        $this->publisher->expects(self::never())->method('publishRefresh');
        $this->publisher->expects(self::never())->method('publishProcess');
        $this->logger->expects(self::never())->method('info');

        $this->reconcile->execute();
    }

    public function testAuditTrailWhereClauseIsASingleOrExpressionNotTwoAndedWheres(): void
    {
        // Regression guard for the Zend_Db_Select boolean-grouping trap:
        // two separate where() calls are AND-ed together by Magento's
        // collection, which would silently turn the intended
        //   (no audit trail row yet) OR (errored under the retry cap)
        // into an always-false-ish AND of the two halves. The condition must
        // be built as a single where() call with an explicit OR inside it.
        $this->configureConfig(50, 3);
        $this->configureStores([1 => true]);
        $auditTrailCollection = $this->collectionWithIds([]);
        $this->collectionFactory->method('create')->willReturnOnConsecutiveCalls(
            $this->emptyCollection(),
            $this->emptyCollection(),
            $auditTrailCollection
        );

        $this->reconcile->execute();

        $whereCalls = $this->whereCalls;
        self::assertCount(
            1,
            $whereCalls,
            'the fetch_status/fetch_attempts condition must be a single where() call so Zend_Db_Select ' .
            'does not AND it together with another OR clause'
        );
        self::assertStringContainsString('OR', $whereCalls[0][0]);
        self::assertStringContainsString(
            "'" . AuditTrailInterface::FETCH_STATUS_ERROR . "'",
            $whereCalls[0][0],
            'the errored-and-still-retryable branch must reference FETCH_STATUS_ERROR'
        );
        self::assertStringContainsString('3', $whereCalls[0][0], 'the max retries value must be bound into the condition');

        $joinLeftCalls = $this->joinLeftCalls;
        self::assertCount(1, $joinLeftCalls, 'the audit trail table must be joined exactly once');
    }

    public function testAuditTrailWhereClauseAlsoMatchesPendingFetchStatus(): void
    {
        // Regression guard: AuditTrailRecorder creates the audit_trail row
        // with FETCH_STATUS_PENDING at send() time, before the document ever
        // reaches SIGNED. The dispatch condition must therefore also match
        // fetch_status = 'pending', not only IS NULL / 'error', otherwise the
        // certificate fetch never fires for the population it targets.
        $this->configureConfig(50, 3);
        $this->configureStores([1 => true]);
        $auditTrailCollection = $this->collectionWithIds([]);
        $this->collectionFactory->method('create')->willReturnOnConsecutiveCalls(
            $this->emptyCollection(),
            $this->emptyCollection(),
            $auditTrailCollection
        );

        $this->reconcile->execute();

        $whereCalls = $this->whereCalls;
        self::assertCount(1, $whereCalls);
        self::assertStringContainsString(
            "'" . AuditTrailInterface::FETCH_STATUS_PENDING . "'",
            $whereCalls[0][0],
            'the condition must also match a fetch_status of pending, not only NULL/error'
        );
    }

    public function testAuditTrailDispatchOnlyCoversStoresCollectingData(): void
    {
        // A store that does not collect never gets an audit trail row: its
        // signed documents must not be queued (the consumer would skip them
        // and they would be picked again on every run).
        $this->configureConfig(50, 3);
        $this->configureStores([1 => false, 2 => true]);
        $this->collectionFactory->method('create')->willReturnOnConsecutiveCalls(
            $this->emptyCollection(),
            $this->emptyCollection(),
            $this->collectionWithIds([])
        );

        $this->reconcile->execute();

        self::assertContains(['main_table.store_id', ['in' => [2]]], $this->filterCalls);
    }

    public function testAuditTrailDispatchIsSkippedWhenNoStoreCollectsData(): void
    {
        $this->configureConfig(50, 3);
        $this->configureStores([1 => false]);
        $this->collectionFactory->expects(self::exactly(2))->method('create')->willReturnOnConsecutiveCalls(
            $this->emptyCollection(),
            $this->emptyCollection()
        );
        $this->publisher->expects(self::never())->method('publishAuditTrailFetch');

        $this->reconcile->execute();
    }

    public function testAuditTrailDispatchRequiresAnExistingRow(): void
    {
        // The row is created when the document is sent with collection on;
        // documents signed before the opt-in are not fetched retroactively.
        $this->configureConfig(50, 3);
        $this->configureStores([1 => true]);
        $this->collectionFactory->method('create')->willReturnOnConsecutiveCalls(
            $this->emptyCollection(),
            $this->emptyCollection(),
            $this->collectionWithIds([])
        );

        $this->reconcile->execute();

        self::assertCount(1, $this->whereCalls);
        self::assertStringNotContainsString('IS NULL', $this->whereCalls[0][0]);
    }

    public function testAuditTrailStalenessIsMeasuredOnTheAuditTrailRowOldestFirst(): void
    {
        $this->configureConfig(50, 3);
        $this->configureStores([1 => true]);
        $this->collectionFactory->method('create')->willReturnOnConsecutiveCalls(
            $this->emptyCollection(),
            $this->emptyCollection(),
            $this->collectionWithIds([])
        );

        $this->reconcile->execute();

        $fields = array_column($this->filterCalls, 0);
        self::assertContains('audit_trail.updated_at', $fields);
        self::assertNotContains('main_table.updated_at', $fields);
        self::assertContains(['audit_trail.updated_at', 'ASC'], $this->orderCalls);
    }

    public function testQueuedDocumentsAreTouchedSoTheyWaitBeforeBeingQueuedAgain(): void
    {
        // Without this, a pending/errored row is re-published on every run
        // while the consumer lags, and every duplicate hits the provider API.
        $this->configureConfig(50, 3);
        $this->configureStores([1 => true]);
        $this->collectionFactory->method('create')->willReturnOnConsecutiveCalls(
            $this->emptyCollection(),
            $this->emptyCollection(),
            $this->collectionWithIds([11, 12])
        );

        $this->reconcile->execute();

        self::assertCount(1, $this->updateCalls);
        [$table, $bind, $where] = $this->updateCalls[0];
        self::assertSame(AuditTrail::MAIN_TABLE, $table);
        self::assertArrayHasKey('updated_at', $bind);
        self::assertSame(['document_id IN (?)' => [11, 12]], $where);
    }
}
