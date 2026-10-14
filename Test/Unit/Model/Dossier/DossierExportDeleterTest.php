<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Dossier;

use MageOS\DigitalSignature\Api\Data\DossierExportInterface;
use MageOS\DigitalSignature\Api\DossierExportRepositoryInterface;
use MageOS\DigitalSignature\Model\Dossier\DossierExportDeleter;
use MageOS\DigitalSignature\Model\Dossier\DossierFileLocator;
use MageOS\DigitalSignature\Model\DossierExport;
use MageOS\DigitalSignature\TestSupport\ModelDoubles;
use Magento\Framework\Exception\FileSystemException;
use PHPUnit\Framework\TestCase;

class DossierExportDeleterTest extends TestCase
{
    use ModelDoubles;

    public function testDeletesTheFileThenTheRow(): void
    {
        $export = $this->newModel(DossierExport::class);
        $export->setData(DossierExportInterface::FILE_PATH, 'digitalsignature/dossiers/dossier-3-a.zip');
        $locator = $this->createMock(DossierFileLocator::class);
        $locator->expects(self::once())->method('delete')->with('digitalsignature/dossiers/dossier-3-a.zip');
        $repository = $this->createMock(DossierExportRepositoryInterface::class);
        $repository->expects(self::once())->method('delete')->with($export);

        (new DossierExportDeleter($repository, $locator))->delete($export);
    }

    public function testKeepsTheRowWhenTheFileCannotBeDeleted(): void
    {
        $export = $this->newModel(DossierExport::class);
        $export->setData(DossierExportInterface::FILE_PATH, 'digitalsignature/dossiers/dossier-3-a.zip');
        $locator = $this->createStub(DossierFileLocator::class);
        $locator->method('delete')->willThrowException(new FileSystemException(__('denied')));
        $repository = $this->createMock(DossierExportRepositoryInterface::class);
        $repository->expects(self::never())->method('delete');

        $this->expectException(FileSystemException::class);
        (new DossierExportDeleter($repository, $locator))->delete($export);
    }
}
