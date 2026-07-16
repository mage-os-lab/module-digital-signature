<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Api;

use MageOS\DigitalSignature\Api\Data\DocumentInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;

interface DocumentRepositoryInterface
{
    /**
     * @throws CouldNotSaveException
     */
    public function save(DocumentInterface $document): DocumentInterface;

    /**
     * @throws NoSuchEntityException
     */
    public function getById(int $documentId): DocumentInterface;

    /**
     * Registra un evento nello storico del documento.
     */
    public function addLog(
        DocumentInterface $document,
        string $event,
        ?string $statusFrom = null,
        ?string $statusTo = null,
        ?string $message = null,
        ?string $payload = null
    ): void;

    /**
     * Retention GDPR: azzera i payload grezzi dello storico eventi del
     * documento (mantiene evento/stato/messaggio/data per l'audit).
     */
    public function purgeLogPayloads(DocumentInterface $document): void;
}
