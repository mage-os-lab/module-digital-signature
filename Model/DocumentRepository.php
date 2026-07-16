<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model;

use MageOS\DigitalSignature\Api\Data\DocumentInterface;
use MageOS\DigitalSignature\Api\DocumentRepositoryInterface;
use MageOS\DigitalSignature\Model\ResourceModel\Document as DocumentResource;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;

class DocumentRepository implements DocumentRepositoryInterface
{
    public function __construct(
        private readonly DocumentResource $resource,
        private readonly DocumentFactory $documentFactory
    ) {
    }

    public function save(DocumentInterface $document): DocumentInterface
    {
        try {
            $this->resource->save($document);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('Impossibile salvare il documento: %1', $e->getMessage()), $e);
        }

        return $document;
    }

    public function getById(int $documentId): DocumentInterface
    {
        $document = $this->documentFactory->create();
        $this->resource->load($document, $documentId);
        if (!$document->getDocumentId()) {
            throw new NoSuchEntityException(__('Documento con id "%1" inesistente.', $documentId));
        }

        return $document;
    }

    public function addLog(
        DocumentInterface $document,
        string $event,
        ?string $statusFrom = null,
        ?string $statusTo = null,
        ?string $message = null,
        ?string $payload = null
    ): void {
        $documentId = $document->getDocumentId();
        if ($documentId === null) {
            return;
        }
        $this->resource->addLog($documentId, $event, $statusFrom, $statusTo, $message, $payload);
    }

    public function purgeLogPayloads(DocumentInterface $document): void
    {
        $documentId = $document->getDocumentId();
        if ($documentId === null) {
            return;
        }
        $this->resource->clearLogPayloads($documentId);
    }
}
