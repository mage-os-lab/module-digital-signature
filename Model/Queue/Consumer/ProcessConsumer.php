<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Queue\Consumer;

use MageOS\DigitalSignature\Api\DocumentRepositoryInterface;
use MageOS\DigitalSignature\Exception\ProviderException;
use MageOS\DigitalSignature\Model\Document\Status;
use MageOS\DigitalSignature\Model\Notification\Notifier;
use MageOS\DigitalSignature\Model\Service\DocumentProcessor;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

/**
 * Consumer for the digitalsignature.document.process topic: generates the PDF
 * and starts the signature. Classifies errors: retryable ones increment the
 * counter (re-queued by the reconciliation cron), permanent ones lead to error.
 */
class ProcessConsumer
{
    private const XML_PATH_MAX_RETRIES = 'digital_signature/general/max_retries';

    public function __construct(
        private readonly DocumentRepositoryInterface $documentRepository,
        private readonly DocumentProcessor $documentProcessor,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Notifier $notifier,
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
        if (!in_array($document->getStatus(), [Status::PENDING, Status::GENERATED], true)) {
            return;
        }

        try {
            $this->documentProcessor->process($document);
        } catch (ProviderException $e) {
            if ($e->isRetryable()) {
                $this->handleRetryable($document, $e->getMessage());
            } else {
                $this->fail($document, $e->getMessage());
            }
        } catch (\Exception $e) {
            // Unclassified errors (missing tag, missing file...): permanent
            $this->fail($document, $e->getMessage());
        }
    }

    private function handleRetryable($document, string $message): void
    {
        $maxRetries = max(1, (int)$this->scopeConfig->getValue(self::XML_PATH_MAX_RETRIES));
        $document->setRetryCount($document->getRetryCount() + 1);
        if ($document->getRetryCount() >= $maxRetries) {
            $this->fail($document, $message . ' (tentativi esauriti)');

            return;
        }
        $document->setErrorMessage($message);
        $this->documentRepository->save($document);
        $this->documentRepository->addLog(
            $document,
            'retry',
            null,
            null,
            sprintf('Errore temporaneo (tentativo %d): %s', $document->getRetryCount(), $message)
        );
        $this->logger->warning(
            sprintf('DigitalSignature: retry documento %d: %s', (int)$document->getDocumentId(), $message)
        );
    }

    private function fail($document, string $message): void
    {
        $previousStatus = $document->getStatus();
        $document->setStatus(Status::ERROR);
        $document->setErrorMessage($message);
        $this->documentRepository->save($document);
        $this->documentRepository->addLog($document, 'error', $previousStatus, Status::ERROR, $message);
        $this->logger->error(
            sprintf('DigitalSignature: documento %d in errore: %s', (int)$document->getDocumentId(), $message)
        );
        $this->notifier->notifyDocumentError($document, $message);
    }
}
