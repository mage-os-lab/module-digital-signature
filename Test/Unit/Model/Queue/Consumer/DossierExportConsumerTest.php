<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Queue\Consumer;

use MageOS\DigitalSignature\Api\Data\DossierExportInterface;
use MageOS\DigitalSignature\Api\DossierExportRepositoryInterface;
use MageOS\DigitalSignature\Model\Dossier\DossierExportConfig;
use MageOS\DigitalSignature\Model\Dossier\DossierExportNotifier;
use MageOS\DigitalSignature\Model\Dossier\DossierExportStateMachine;
use MageOS\DigitalSignature\Model\Dossier\DossierFileLocator;
use MageOS\DigitalSignature\Model\DossierExport;
use MageOS\DigitalSignature\Model\Queue\Consumer\DossierExportConsumer;
use MageOS\DigitalSignature\Model\Service\DossierBuilder;
use MageOS\DigitalSignature\TestSupport\ModelDoubles;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DossierExportConsumerTest extends TestCase
{
    use ModelDoubles;

    private const PATH = 'digitalsignature/dossiers/dossier-12-abc.zip';

    private DossierExportRepositoryInterface&MockObject $repository;
    private DossierExportStateMachine&MockObject $stateMachine;
    private DossierBuilder&MockObject $builder;
    private DossierFileLocator&MockObject $locator;
    private DossierExportNotifier&MockObject $notifier;
    private DossierExportConsumer $consumer;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(DossierExportRepositoryInterface::class);
        $this->stateMachine = $this->createMock(DossierExportStateMachine::class);
        $this->builder = $this->createMock(DossierBuilder::class);
        $this->locator = $this->createMock(DossierFileLocator::class);
        $this->notifier = $this->createMock(DossierExportNotifier::class);
        $config = $this->createStub(DossierExportConfig::class);
        $config->method('getRetentionHours')->willReturn(72);

        $this->locator->method('newRelativePath')->with('dossier-12')->willReturn(self::PATH);
        $this->locator->method('absolutePath')->with(self::PATH)->willReturn('/var/www/var/' . self::PATH);
        $this->locator->method('size')->willReturn(2048);

        $this->consumer = new DossierExportConsumer(
            $this->repository,
            $this->stateMachine,
            $this->builder,
            $this->locator,
            $config,
            $this->notifier,
            $this->createStub(LoggerInterface::class)
        );
    }

    private function queuedExport(string $status = DossierExportInterface::STATUS_QUEUED): DossierExport
    {
        $export = $this->newModel(DossierExport::class);
        $export->setData(DossierExportInterface::EXPORT_ID, 12);
        $export->setStatus($status);
        $export->setDocumentIds([7, 9]);
        $export->setRequestedBy('admin_jane');
        $this->repository->method('getById')->with(12)->willReturn($export);

        return $export;
    }

    public function testBuildsTheZipMarksItReadyAndNotifies(): void
    {
        $this->queuedExport();
        $this->stateMachine->expects(self::once())->method('claim')->with(12, self::PATH)->willReturn(true);
        $this->stateMachine->expects(self::exactly(2))->method('heartbeat')->willReturn(true);
        $this->builder->expects(self::once())->method('buildToFile')->willReturnCallback(
            function (array $ids, string $target, ?string $by, callable $onProgress) {
                self::assertSame([7, 9], $ids);
                self::assertSame('/var/www/var/' . self::PATH, $target);
                self::assertSame('admin_jane', $by);
                $onProgress(1);
                $onProgress(2);
            }
        );
        $this->stateMachine->expects(self::once())->method('markReady')->with(12, 2048, 72)->willReturn(true);
        $this->notifier->expects(self::once())->method('ready')->with(12, 2, 72);
        $this->locator->expects(self::never())->method('delete');

        $this->consumer->process('12');
    }

    public function testIgnoresAMissingJob(): void
    {
        $this->repository->method('getById')->willThrowException(new NoSuchEntityException(__('gone')));
        $this->stateMachine->expects(self::never())->method('claim');

        $this->consumer->process('12');
    }

    public function testIgnoresAJobThatIsNotQueued(): void
    {
        $this->queuedExport(DossierExportInterface::STATUS_READY);
        $this->stateMachine->expects(self::never())->method('claim');
        $this->builder->expects(self::never())->method('buildToFile');

        $this->consumer->process('12');
    }

    public function testDoesNothingWhenAnotherConsumerClaimedTheJob(): void
    {
        $this->queuedExport();
        $this->stateMachine->method('claim')->willReturn(false);
        $this->builder->expects(self::never())->method('buildToFile');
        $this->notifier->expects(self::never())->method(self::anything());

        $this->consumer->process('12');
    }

    public function testStopsAndRemovesFileWhenHeartbeatFindsNoRow(): void
    {
        // Job deleted from the grid (or declared interrupted by the cron)
        // while this consumer was still building it.
        $this->queuedExport();
        $this->stateMachine->method('claim')->willReturn(true);
        $this->stateMachine->method('heartbeat')->willReturn(false);
        $this->builder->method('buildToFile')->willReturnCallback(
            function (array $ids, string $target, ?string $by, callable $onProgress) {
                $onProgress(1);
                self::fail('the build must stop at the first failed heartbeat');
            }
        );
        $this->locator->expects(self::once())->method('delete')->with(self::PATH);
        $this->stateMachine->expects(self::never())->method('markFailed');
        $this->stateMachine->expects(self::never())->method('markReady');
        $this->notifier->expects(self::never())->method(self::anything());

        $this->consumer->process('12');
    }

    public function testRemovesTheFileWhenTheJobVanishesBeforeBeingMarkedReady(): void
    {
        $this->queuedExport();
        $this->stateMachine->method('claim')->willReturn(true);
        $this->stateMachine->method('markReady')->willReturn(false);
        $this->locator->expects(self::once())->method('delete')->with(self::PATH);
        $this->notifier->expects(self::never())->method(self::anything());

        $this->consumer->process('12');
    }

    public function testFailureMarksTheJobFailedRemovesTheFileAndNotifies(): void
    {
        $this->queuedExport();
        $this->stateMachine->method('claim')->willReturn(true);
        $this->builder->method('buildToFile')->willThrowException(new \RuntimeException('disk full'));
        $this->locator->expects(self::once())->method('delete')->with(self::PATH);
        $this->stateMachine->expects(self::once())->method('markFailed')->with(12, 'disk full')->willReturn(true);
        $this->notifier->expects(self::once())->method('failed')->with(12, 'disk full');

        $this->consumer->process('12');
    }

    public function testFailureAfterTheCronAlreadyFailedTheJobDoesNotNotifyTwice(): void
    {
        $this->queuedExport();
        $this->stateMachine->method('claim')->willReturn(true);
        $this->builder->method('buildToFile')->willThrowException(new \RuntimeException('disk full'));
        $this->stateMachine->method('markFailed')->willReturn(false);
        $this->notifier->expects(self::never())->method('failed');

        $this->consumer->process('12');
    }
}
