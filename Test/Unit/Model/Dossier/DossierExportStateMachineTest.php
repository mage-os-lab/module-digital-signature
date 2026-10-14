<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Dossier;

use MageOS\DigitalSignature\Model\Dossier\DossierExportStateMachine;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\TestCase;

class DossierExportStateMachineTest extends TestCase
{
    private const NOW = 1_800_000_000; // 2027-01-15 08:00:00 UTC

    /** @var array<int, array{0: string, 1: array, 2: array}> */
    private array $updates = [];
    private int $affectedRows = 1;
    private DossierExportStateMachine $stateMachine;

    protected function setUp(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('update')->willReturnCallback(function ($table, array $bind, $where) {
            $this->updates[] = [$table, $bind, $where];

            return $this->affectedRows;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(self::NOW);

        $this->stateMachine = new DossierExportStateMachine($resource, $dateTime);
    }

    public function testClaimMovesOnlyAQueuedJobToProcessing(): void
    {
        self::assertTrue($this->stateMachine->claim(12, 'digitalsignature/dossiers/dossier-12-abc.zip'));

        [$table, $bind, $where] = $this->updates[0];
        self::assertSame('mageos_digitalsignature_dossier_export', $table);
        self::assertSame('processing', $bind['status']);
        self::assertSame('digitalsignature/dossiers/dossier-12-abc.zip', $bind['file_path']);
        self::assertSame('2027-01-15 08:00:00', $bind['started_at']);
        self::assertSame('2027-01-15 08:00:00', $bind['heartbeat_at']);
        self::assertSame(0, $bind['processed_documents']);
        self::assertSame(['export_id = ?' => 12, 'status = ?' => 'queued'], $where);
    }

    public function testClaimFailsWhenAnotherConsumerAlreadyClaimedIt(): void
    {
        $this->affectedRows = 0;

        self::assertFalse($this->stateMachine->claim(12, 'x.zip'));
    }

    public function testHeartbeatAlwaysChangesTheRow(): void
    {
        // MySQL counts only changed rows: two heartbeats in the same second
        // would affect 0 rows if only heartbeat_at were written.
        $this->stateMachine->heartbeat(12, 1);
        $this->stateMachine->heartbeat(12, 2);

        self::assertSame(1, $this->updates[0][1]['processed_documents']);
        self::assertSame(2, $this->updates[1][1]['processed_documents']);
        self::assertSame(['export_id = ?' => 12, 'status = ?' => 'processing'], $this->updates[0][2]);
    }

    public function testHeartbeatReportsAJobNoLongerProcessing(): void
    {
        $this->affectedRows = 0;

        self::assertFalse($this->stateMachine->heartbeat(12, 3));
    }

    public function testMarkReadySetsSizeAndExpiry(): void
    {
        self::assertTrue($this->stateMachine->markReady(12, 2048, 72));

        [, $bind, $where] = $this->updates[0];
        self::assertSame('ready', $bind['status']);
        self::assertSame(2048, $bind['file_size']);
        self::assertSame('2027-01-15 08:00:00', $bind['completed_at']);
        self::assertSame('2027-01-18 08:00:00', $bind['expires_at']);
        self::assertSame(['export_id = ?' => 12, 'status = ?' => 'processing'], $where);
    }

    public function testMarkFailedOnlyFromProcessing(): void
    {
        self::assertTrue($this->stateMachine->markFailed(12, 'disk full'));

        [, $bind, $where] = $this->updates[0];
        self::assertSame('failed', $bind['status']);
        self::assertSame('disk full', $bind['error']);
        self::assertSame(['export_id = ?' => 12, 'status = ?' => 'processing'], $where);
    }

    public function testMarkInterruptedRechecksTheHeartbeatInTheSameStatement(): void
    {
        self::assertTrue($this->stateMachine->markInterrupted(12, '2027-01-15 07:45:00'));

        [, $bind, $where] = $this->updates[0];
        self::assertSame('failed', $bind['status']);
        self::assertSame(DossierExportStateMachine::INTERRUPTED_ERROR, $bind['error']);
        self::assertSame(
            ['export_id = ?' => 12, 'status = ?' => 'processing', 'heartbeat_at < ?' => '2027-01-15 07:45:00'],
            $where
        );
    }

    public function testMarkInterruptedLosesToALiveConsumer(): void
    {
        $this->affectedRows = 0;

        self::assertFalse($this->stateMachine->markInterrupted(12, '2027-01-15 07:45:00'));
    }

    public function testMarkExpiredClearsThePathOnlyFromReady(): void
    {
        self::assertTrue($this->stateMachine->markExpired(12));

        [, $bind, $where] = $this->updates[0];
        self::assertSame(['status' => 'expired', 'file_path' => null], $bind);
        self::assertSame(['export_id = ?' => 12, 'status = ?' => 'ready'], $where);
    }
}
