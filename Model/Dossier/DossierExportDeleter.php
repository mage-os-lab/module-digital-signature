<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Dossier;

use MageOS\DigitalSignature\Api\Data\DossierExportInterface;
use MageOS\DigitalSignature\Api\DossierExportRepositoryInterface;

/**
 * Admin delete of a dossier export: the ZIP (personal data) first, then the
 * row. A consumer still building a deleted job notices at its next heartbeat
 * and removes its own file.
 */
class DossierExportDeleter
{
    public function __construct(
        private readonly DossierExportRepositoryInterface $repository,
        private readonly DossierFileLocator $fileLocator
    ) {
    }

    /**
     * @throws \Magento\Framework\Exception\FileSystemException when the file cannot be deleted (row kept)
     * @throws \Magento\Framework\Exception\CouldNotDeleteException
     */
    public function delete(DossierExportInterface $export): void
    {
        if ($export->getFilePath() !== null) {
            $this->fileLocator->delete($export->getFilePath());
        }
        $this->repository->delete($export);
    }
}
