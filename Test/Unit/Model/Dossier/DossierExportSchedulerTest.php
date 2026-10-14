<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Dossier;

use MageOS\DigitalSignature\Api\Data\DossierExportInterface;
use MageOS\DigitalSignature\Api\DossierExportRepositoryInterface;
use MageOS\DigitalSignature\Model\Dossier\DossierExportScheduler;
use MageOS\DigitalSignature\Model\DossierExport;
use MageOS\DigitalSignature\Model\DossierExportFactory;
use MageOS\DigitalSignature\Model\Queue\Publisher;
use MageOS\DigitalSignature\TestSupport\ModelDoubles;
use PHPUnit\Framework\TestCase;

class DossierExportSchedulerTest extends TestCase
{
    use ModelDoubles;

    public function testCreatesAQueuedJobWithASnapshotOfTheSelectionAndPublishesIt(): void
    {
        $export = $this->newModel(DossierExport::class);
        $factory = $this->createMock(DossierExportFactory::class);
        $factory->method('create')->willReturn($export);
        $repository = $this->createMock(DossierExportRepositoryInterface::class);
        $repository->expects(self::once())->method('save')->with($export)->willReturnCallback(
            function (DossierExport $saved) {
                $saved->setData(DossierExportInterface::EXPORT_ID, 12);

                return $saved;
            }
        );
        $publisher = $this->createMock(Publisher::class);
        $publisher->expects(self::once())->method('publishDossierExport')->with(12);

        $exportId = (new DossierExportScheduler($factory, $repository, $publisher))
            ->schedule([7, 9, 30], 4, 'admin_jane');

        self::assertSame(12, $exportId);
        self::assertSame(DossierExportInterface::STATUS_QUEUED, $export->getStatus());
        self::assertSame([7, 9, 30], $export->getDocumentIds());
        self::assertSame(3, $export->getDocumentCount());
        self::assertSame(4, $export->getRequestedByUserId());
        self::assertSame('admin_jane', $export->getRequestedBy());
    }
}
