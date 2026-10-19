<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Dossier;

use MageOS\DigitalSignature\Api\Data\DossierExportInterface;
use MageOS\DigitalSignature\Api\DossierExportRepositoryInterface;
use MageOS\DigitalSignature\Model\DossierExportFactory;
use MageOS\DigitalSignature\Model\Queue\Publisher;

/**
 * Records a queued dossier export (with a snapshot of the selected document
 * IDs) and hands it to the digitalsignatureDossierExport consumer.
 */
class DossierExportScheduler
{
    public function __construct(
        private readonly DossierExportFactory $dossierExportFactory,
        private readonly DossierExportRepositoryInterface $repository,
        private readonly Publisher $publisher
    ) {
    }

    /**
     * @param int[] $documentIds
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     */
    public function schedule(array $documentIds, ?int $userId, ?string $username): int
    {
        /** @var DossierExportInterface $export */
        $export = $this->dossierExportFactory->create();
        $export->setStatus(DossierExportInterface::STATUS_QUEUED);
        $export->setDocumentIds($documentIds);
        $export->setRequestedByUserId($userId);
        $export->setRequestedBy($username);
        $this->repository->save($export);

        $exportId = (int)$export->getExportId();
        try {
            $this->publisher->publishDossierExport($exportId);
        } catch (\Throwable $e) {
            // Without its message the job would stay queued forever: nothing else picks up queued rows
            $this->repository->delete($export);
            throw $e;
        }

        return $exportId;
    }
}
