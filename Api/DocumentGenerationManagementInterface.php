<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Api;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

interface DocumentGenerationManagementInterface
{
    /**
     * Queues document generation for the order (same behavior as the
     * admin "Generate signature documents" button): not synchronous, no id
     * returned because an order can generate zero, one, or more documents.
     *
     * @param int $orderId
     * @return void
     * @throws NoSuchEntityException if the order does not exist or is outside the caller's scope
     */
    public function generate(int $orderId): void;

    /**
     * Regenerates the specified document.
     *
     * @param int $documentId
     * @return int id of the new document
     * @throws NoSuchEntityException if the document does not exist or is outside the caller's scope
     * @throws LocalizedException if the document is not active
     */
    public function regenerate(int $documentId): int;
}
