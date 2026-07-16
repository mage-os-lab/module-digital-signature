<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Queue\Consumer;

use MageOS\DigitalSignature\Api\DocumentRepositoryInterface;
use MageOS\DigitalSignature\Exception\ProviderException;
use MageOS\DigitalSignature\Model\Service\DocumentProcessor;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

/**
 * Consumer del topic digitalsignature.document.refresh: interroga il provider
 * e riallinea lo stato. Idempotente; gli errori vengono solo loggati
 * (il cron di riconciliazione riproverà al giro successivo).
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
                'Errore aggiornamento stato: ' . $e->getMessage()
            );
            $this->logger->warning(
                sprintf(
                    'DigitalSignature: refresh documento %d fallito: %s',
                    (int)$document->getDocumentId(),
                    $e->getMessage()
                )
            );
        }
    }
}
