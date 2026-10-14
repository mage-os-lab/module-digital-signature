<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Cron;

use MageOS\DigitalSignature\Api\Data\DossierExportInterface;
use MageOS\DigitalSignature\Api\DossierExportRepositoryInterface;
use MageOS\DigitalSignature\Cron\DossierExportCleanup;
use MageOS\DigitalSignature\Model\Dossier\DossierExportNotifier;
use MageOS\DigitalSignature\Model\Dossier\DossierExportStateMachine;
use MageOS\DigitalSignature\Model\Dossier\DossierFileLocator;
use MageOS\DigitalSignature\Model\DossierExport;
use MageOS\DigitalSignature\Model\ResourceModel\DossierExport\Collection;
use MageOS\DigitalSignature\Model\ResourceModel\DossierExport\CollectionFactory;
use MageOS\DigitalSignature\TestSupport\ModelDoubles;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DossierExportCleanupTest extends TestCase
{
    use ModelDoubles;

    private const NOW = 1_800_000_000; // 2027-01-15 08:00:00 UTC

    private CollectionFactory&MockObject $collectionFactory;
    private DossierExportRepositoryInterface&MockObject $repository;
    private DossierExportStateMachine&MockObject $stateMachine;
    private DossierFileLocator&MockObject $locator;
    private DossierExportNotifier&MockObject $notifier;
    private DossierExportCleanup $cron;

    /** @var DossierExport[][] expired, stale, old terminal */
    private array $rows = [[], [], []];

    protected function setUp(): void
    {
        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $this->collectionFactory->method('create')->willReturnCallback(
            fn () => $this->newCollection(Collection::class, array_shift($this->rows) ?? [])
        );
        $this->repository = $this->createMock(DossierExportRepositoryInterface::class);
        $this->stateMachine = $this->createMock(DossierExportStateMachine::class);
        $this->stateMachine->method('now')->willReturn('2027-01-15 08:00:00');
        $this->locator = $this->createMock(DossierFileLocator::class);
        $this->notifier = $this->createMock(DossierExportNotifier::class);
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(self::NOW);

        $this->cron = new DossierExportCleanup(
            $this->collectionFactory,
            $this->repository,
            $this->stateMachine,
            $this->locator,
            $this->notifier,
            $dateTime,
            $this->createStub(LoggerInterface::class)
        );
    }

    private function row(int $id, ?string $filePath = null): DossierExport
    {
        $export = $this->newModel(DossierExport::class);
        $export->setData(DossierExportInterface::EXPORT_ID, $id);
        $export->setData(DossierExportInterface::FILE_PATH, $filePath);

        return $export;
    }

    public function testExpiredExportsLoseTheirFileThenTheirPath(): void
    {
        $this->rows[0] = [$this->row(3, 'digitalsignature/dossiers/dossier-3-a.zip')];
        $this->locator->expects(self::once())->method('delete')->with('digitalsignature/dossiers/dossier-3-a.zip');
        $this->stateMachine->expects(self::once())->method('markExpired')->with(3)->willReturn(true);

        $this->cron->execute();

        self::assertContains(['status', DossierExportInterface::STATUS_READY], $this->filterCalls);
        self::assertContains(['expires_at', ['lt' => '2027-01-15 08:00:00']], $this->filterCalls);
    }

    public function testExpiredExportIsRetriedWhenItsFileCannotBeDeleted(): void
    {
        $this->rows[0] = [$this->row(3, 'digitalsignature/dossiers/dossier-3-a.zip')];
        $this->locator->method('delete')->willThrowException(new FileSystemException(__('denied')));
        $this->stateMachine->expects(self::never())->method('markExpired');

        $this->cron->execute();
    }

    public function testJobsWithoutHeartbeatAreFailedOnlyWhenTheConditionalUpdateWins(): void
    {
        $this->rows[1] = [
            $this->row(5, 'digitalsignature/dossiers/dossier-5-b.zip'),
            $this->row(6, 'digitalsignature/dossiers/dossier-6-c.zip'),
        ];
        $this->stateMachine->method('markInterrupted')->willReturnMap([
            [5, '2027-01-15 07:45:00', true],
            [6, '2027-01-15 07:45:00', false], // consumer heartbeat arrived meanwhile
        ]);
        $this->locator->expects(self::once())->method('delete')->with('digitalsignature/dossiers/dossier-5-b.zip');
        $this->notifier->expects(self::once())->method('failed')
            ->with(5, DossierExportStateMachine::INTERRUPTED_ERROR);

        $this->cron->execute();

        self::assertContains(['status', DossierExportInterface::STATUS_PROCESSING], $this->filterCalls);
        self::assertContains(['heartbeat_at', ['lt' => '2027-01-15 07:45:00']], $this->filterCalls);
    }

    public function testOnlyTerminalRowsOlderThanThirtyDaysAreDeleted(): void
    {
        $old = $this->row(8);
        $this->rows[2] = [$old];
        $this->repository->expects(self::once())->method('delete')->with($old);

        $this->cron->execute();

        self::assertContains(
            ['status', ['in' => [DossierExportInterface::STATUS_FAILED, DossierExportInterface::STATUS_EXPIRED]]],
            $this->filterCalls
        );
        self::assertContains(['completed_at', ['lt' => '2026-12-16 08:00:00']], $this->filterCalls);
    }

    public function testStaleTemporaryFilesOfSynchronousExportsAreRemoved(): void
    {
        $this->locator->expects(self::once())->method('deleteTemporaryFilesOlderThan')->with(self::NOW - 86400);

        $this->cron->execute();
    }
}
