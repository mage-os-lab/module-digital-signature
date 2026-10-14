<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model;

use MageOS\DigitalSignature\Api\Data\DossierExportInterface;
use MageOS\DigitalSignature\Api\DossierExportRepositoryInterface;
use MageOS\DigitalSignature\Model\ResourceModel\DossierExport as DossierExportResource;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;

class DossierExportRepository implements DossierExportRepositoryInterface
{
    public function __construct(
        private readonly DossierExportResource $resource,
        private readonly DossierExportFactory $dossierExportFactory
    ) {
    }

    public function save(DossierExportInterface $export): DossierExportInterface
    {
        try {
            $this->resource->save($export);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('Unable to save the dossier export: %1', $e->getMessage()), $e);
        }

        return $export;
    }

    public function getById(int $exportId): DossierExportInterface
    {
        $export = $this->dossierExportFactory->create();
        $this->resource->load($export, $exportId);
        if (!$export->getExportId()) {
            throw new NoSuchEntityException(__('Dossier export with id "%1" does not exist.', $exportId));
        }

        return $export;
    }

    public function delete(DossierExportInterface $export): void
    {
        try {
            $this->resource->delete($export);
        } catch (\Exception $e) {
            throw new CouldNotDeleteException(__('Unable to delete the dossier export: %1', $e->getMessage()), $e);
        }
    }
}
