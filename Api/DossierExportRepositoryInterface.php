<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Api;

use MageOS\DigitalSignature\Api\Data\DossierExportInterface;

interface DossierExportRepositoryInterface
{
    /**
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     */
    public function save(DossierExportInterface $export): DossierExportInterface;

    /**
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getById(int $exportId): DossierExportInterface;

    /**
     * @throws \Magento\Framework\Exception\CouldNotDeleteException
     */
    public function delete(DossierExportInterface $export): void;
}
