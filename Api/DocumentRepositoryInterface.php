<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Api;

use MageOS\DigitalSignature\Api\Data\DocumentInterface;
use MageOS\DigitalSignature\Api\Data\DocumentSearchResultsInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;

interface DocumentRepositoryInterface
{
    /**
     * Saves the document.
     *
     * @param \MageOS\DigitalSignature\Api\Data\DocumentInterface $document
     * @return \MageOS\DigitalSignature\Api\Data\DocumentInterface
     * @throws CouldNotSaveException
     */
    public function save(DocumentInterface $document): DocumentInterface;

    /**
     * Retrieves the document by id.
     *
     * @param int $documentId
     * @return \MageOS\DigitalSignature\Api\Data\DocumentInterface
     * @throws NoSuchEntityException
     */
    public function getById(int $documentId): DocumentInterface;

    /**
     * Records an event in the document's history.
     *
     * @param \MageOS\DigitalSignature\Api\Data\DocumentInterface $document
     * @param string $event
     * @param string|null $statusFrom
     * @param string|null $statusTo
     * @param string|null $message
     * @param string|null $payload
     * @return void
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
     * GDPR retention: clears the raw payloads of the document's event
     * history (keeps event/status/message/date for audit purposes).
     *
     * @param \MageOS\DigitalSignature\Api\Data\DocumentInterface $document
     * @return void
     */
    public function purgeLogPayloads(DocumentInterface $document): void;

    /**
     * Filtered/paginated document list via SearchCriteria.
     *
     * @param \Magento\Framework\Api\SearchCriteriaInterface $searchCriteria
     * @return \MageOS\DigitalSignature\Api\Data\DocumentSearchResultsInterface
     */
    public function getList(SearchCriteriaInterface $searchCriteria): DocumentSearchResultsInterface;
}
