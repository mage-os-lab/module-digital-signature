<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model;

use MageOS\DigitalSignature\Api\Data\DossierExportInterface;
use MageOS\DigitalSignature\Model\ResourceModel\DossierExport as DossierExportResource;
use Magento\Framework\Model\AbstractModel;

class DossierExport extends AbstractModel implements DossierExportInterface
{
    protected $_eventPrefix = 'digitalsignature_dossier_export';

    protected function _construct(): void
    {
        $this->_init(DossierExportResource::class);
    }

    public function getExportId(): ?int
    {
        $id = $this->getData(self::EXPORT_ID);

        return $id === null ? null : (int)$id;
    }

    public function getStatus(): string
    {
        return (string)$this->getData(self::STATUS);
    }

    public function setStatus(string $status): DossierExportInterface
    {
        return $this->setData(self::STATUS, $status);
    }

    public function getDocumentIds(): array
    {
        $decoded = json_decode((string)$this->getData(self::DOCUMENT_IDS), true);

        return is_array($decoded) ? array_map('intval', $decoded) : [];
    }

    public function setDocumentIds(array $documentIds): DossierExportInterface
    {
        $ids = array_values(array_map('intval', $documentIds));
        $this->setData(self::DOCUMENT_IDS, json_encode($ids, JSON_THROW_ON_ERROR));

        return $this->setData(self::DOCUMENT_COUNT, count($ids));
    }

    public function getDocumentCount(): int
    {
        return (int)$this->getData(self::DOCUMENT_COUNT);
    }

    public function getRequestedBy(): ?string
    {
        $value = $this->getData(self::REQUESTED_BY);

        return $value === null || $value === '' ? null : (string)$value;
    }

    public function setRequestedBy(?string $username): DossierExportInterface
    {
        return $this->setData(self::REQUESTED_BY, $username);
    }

    public function getRequestedByUserId(): ?int
    {
        $value = $this->getData(self::REQUESTED_BY_USER_ID);

        return $value === null ? null : (int)$value;
    }

    public function setRequestedByUserId(?int $userId): DossierExportInterface
    {
        return $this->setData(self::REQUESTED_BY_USER_ID, $userId);
    }

    public function getFilePath(): ?string
    {
        $value = $this->getData(self::FILE_PATH);

        return $value === null || $value === '' ? null : (string)$value;
    }

    public function getCompletedAt(): ?string
    {
        $value = $this->getData(self::COMPLETED_AT);

        return $value === null ? null : (string)$value;
    }
}
