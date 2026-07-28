<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model;

use MageOS\DigitalSignature\Api\Data\DocumentInterface;
use MageOS\DigitalSignature\Api\Data\DocumentSearchResultsInterface;
use MageOS\DigitalSignature\Api\Data\DocumentSearchResultsInterfaceFactory;
use MageOS\DigitalSignature\Api\DocumentRepositoryInterface;
use MageOS\DigitalSignature\Model\ResourceModel\Document as DocumentResource;
use MageOS\DigitalSignature\Model\ResourceModel\Document\CollectionFactory;
use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;

class DocumentRepository implements DocumentRepositoryInterface
{
    public function __construct(
        private readonly DocumentResource $resource,
        private readonly DocumentFactory $documentFactory,
        private readonly CollectionFactory $collectionFactory,
        private readonly CollectionProcessorInterface $collectionProcessor,
        private readonly DocumentSearchResultsInterfaceFactory $searchResultsFactory,
        private readonly \Magento\Framework\Event\ManagerInterface $eventManager
    ) {
    }

    public function save(DocumentInterface $document): DocumentInterface
    {
        try {
            $this->resource->save($document);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('Unable to save the document: %1', $e->getMessage()), $e);
        }

        return $document;
    }

    public function getById(int $documentId): DocumentInterface
    {
        $document = $this->documentFactory->create();
        $this->resource->load($document, $documentId);
        if (!$document->getDocumentId()) {
            throw new NoSuchEntityException(__('Document with id "%1" does not exist.', $documentId));
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

        if ($event === 'status_change') {
            $this->eventManager->dispatch('mageos_digitalsignature_document_status_changed', [
                'document' => $document,
                'status_from' => $statusFrom,
                'status_to' => $statusTo,
            ]);
        }
    }

    public function purgeLogPayloads(DocumentInterface $document): void
    {
        $documentId = $document->getDocumentId();
        if ($documentId === null) {
            return;
        }
        $this->resource->clearLogPayloads($documentId);
    }

    public function getList(SearchCriteriaInterface $searchCriteria): DocumentSearchResultsInterface
    {
        $collection = $this->collectionFactory->create();
        $this->collectionProcessor->process($searchCriteria, $collection);

        $searchResults = $this->searchResultsFactory->create();
        $searchResults->setSearchCriteria($searchCriteria);
        $searchResults->setItems($collection->getItems());
        $searchResults->setTotalCount($collection->getSize());

        return $searchResults;
    }
}
