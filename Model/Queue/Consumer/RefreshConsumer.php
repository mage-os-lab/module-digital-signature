<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Queue\Consumer;

use MageOS\DigitalSignature\Api\DocumentRepositoryInterface;
use MageOS\DigitalSignature\Exception\ProviderException;
use MageOS\DigitalSignature\Model\Service\DocumentProcessor;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

/**
 * Consumer for the digitalsignature.document.refresh topic: queries the
 * provider and realigns the status. Idempotent; errors are only logged
 * (the reconciliation cron will retry on the next run).
 */
class RefreshConsumer
{
    public function __construct(
        private readonly DocumentRepositoryInterface $documentRepository,
        private readonly DocumentProcessor $documentProcessor,
        private readonly LoggerInterface $logger
    ) {
    }

    public function process(string $documentId): void
    {
        try {
            $document = $this->documentRepository->getById((int)$documentId);
        } catch (NoSuchEntityException) {
            return;
        }

        try {
            $this->documentProcessor->refreshStatus($document);
        } catch (ProviderException $e) {
            $this->documentRepository->addLog(
                $document,
                'error',
                null,
                null,
                'Status refresh error: ' . $e->getMessage()
            );
            $this->logger->warning(
                sprintf(
                    'DigitalSignature: refresh failed for document %d: %s',
                    (int)$document->getDocumentId(),
                    $e->getMessage()
                )
            );
        }
    }
}
