<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Queue\Consumer;

use MageOS\DigitalSignature\Api\AuditTrailCertificateProviderInterface;
use MageOS\DigitalSignature\Api\AuditTrailRepositoryInterface;
use MageOS\DigitalSignature\Api\Data\AuditTrailInterface;
use MageOS\DigitalSignature\Api\DocumentRepositoryInterface;
use MageOS\DigitalSignature\Api\DocumentStorageInterface;
use MageOS\DigitalSignature\Api\SignProviderPoolInterface;
use MageOS\DigitalSignature\Exception\ProviderException;
use MageOS\DigitalSignature\Model\AuditTrailFactory;
use MageOS\DigitalSignature\Model\Document\Status;
use MageOS\DigitalSignature\Model\Service\AuditTrailComplianceGate;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

/**
 * Consumer for digitalsignature.audit_trail.fetch: fetches the provider's
 * official signing certificate/report, for providers that expose one.
 * Idempotent; errors only increment the retry counter (Cron\Reconcile
 * requeues up to max_retries).
 */
class AuditTrailFetchConsumer
{
    /**
     * fetch_attempts value that marks a permanent failure: the column's
     * maximum (SMALLINT UNSIGNED), always above any configured max_retries.
     */
    private const PERMANENT_FAILURE_ATTEMPTS = 65535;

    public function __construct(
        private readonly DocumentRepositoryInterface $documentRepository,
        private readonly AuditTrailRepositoryInterface $auditTrailRepository,
        private readonly AuditTrailFactory $auditTrailFactory,
        private readonly SignProviderPoolInterface $providerPool,
        private readonly DocumentStorageInterface $storage,
        private readonly AuditTrailComplianceGate $complianceGate,
        private readonly LoggerInterface $logger
    ) {
    }

    public function process(string $documentId): void
    {
        try {
            $document = $this->documentRepository->getById((int)$documentId);
        } catch (NoSuchEntityException $e) {
            $this->logger->debug(
                sprintf('DigitalSignature: audit trail fetch skipped, document %s not found: %s', $documentId, $e->getMessage())
            );

            return;
        }

        if (!$this->complianceGate->isDataCollectionEnabled((int)$document->getStoreId())) {
            $this->logger->debug(
                sprintf(
                    'DigitalSignature: audit trail fetch skipped for document %d, data collection disabled for store %d',
                    (int)$document->getDocumentId(),
                    (int)$document->getStoreId()
                )
            );

            return;
        }

        // The message may have been queued before the document was purged by
        // retention (or before a status change): fetching now would bring the
        // signer's personal data back.
        if ($document->getPurgedAt() !== null || $document->getStatus() !== Status::SIGNED) {
            $this->logger->debug(sprintf(
                'DigitalSignature: audit trail fetch skipped for document %d, purged or no longer signed',
                (int)$document->getDocumentId()
            ));

            return;
        }

        $id = (int)$document->getDocumentId();
        $auditTrail = $this->auditTrailRepository->getByDocumentId($id);
        if ($auditTrail === null) {
            $auditTrail = $this->auditTrailFactory->create();
            $auditTrail->isObjectNew(true);
            $auditTrail->setDocumentId($id);
            $auditTrail->setProviderCode($document->getProviderCode());
        }

        try {
            $provider = $this->providerPool->get($document->getProviderCode());
            if (!$provider instanceof AuditTrailCertificateProviderInterface) {
                $auditTrail->setFetchStatus(AuditTrailInterface::FETCH_STATUS_UNAVAILABLE);
                $auditTrail->setFetchedAt(date('Y-m-d H:i:s'));
                $this->auditTrailRepository->save($auditTrail);

                return;
            }

            $result = $provider->fetchCertificate($document);
            $certificatePath = sprintf('order_%d/document_%d_certificate.pdf', $document->getOrderId(), $id);
            $this->storage->write($certificatePath, $result->getCertificateContent());

            $auditTrail->setCertificatePath($certificatePath);
            $auditTrail->setSignerIp($result->getSignerIp());
            $auditTrail->setAuthenticationMethod($result->getAuthenticationMethod());
            $auditTrail->setTransactionId($result->getTransactionId());
            $auditTrail->setFetchStatus(AuditTrailInterface::FETCH_STATUS_FETCHED);
            $auditTrail->setFetchedAt(date('Y-m-d H:i:s'));
            $this->auditTrailRepository->save($auditTrail);

            return;
        } catch (ProviderException $e) {
            // A permanent failure jumps to PERMANENT_FAILURE_ATTEMPTS so
            // Reconcile's "fetch_attempts < max_retries" check never matches
            // it again: the consumer does not know the configured threshold.
            $auditTrail->setFetchAttempts($e->isRetryable() ? $auditTrail->getFetchAttempts() + 1 : self::PERMANENT_FAILURE_ATTEMPTS);
            $auditTrail->setLastError($e->getMessage());
            $auditTrail->setFetchStatus(AuditTrailInterface::FETCH_STATUS_ERROR);
            $this->logger->warning(
                sprintf('DigitalSignature: audit trail certificate fetch failed for document %d: %s', $id, $e->getMessage())
            );
        } catch (\Throwable $e) {
            // Any other failure mode (missing provider config, filesystem
            // error writing the certificate, a TypeError from a malformed
            // provider response, ...) must not escape to the queue
            // framework uncaught: an uncaught exception here means
            // fetch_attempts never increments and Reconcile requeues the
            // same document (and re-hits the provider API) on every cron
            // run forever.
            $auditTrail->setFetchAttempts($auditTrail->getFetchAttempts() + 1);
            $auditTrail->setLastError($e->getMessage());
            $auditTrail->setFetchStatus(AuditTrailInterface::FETCH_STATUS_ERROR);
            $this->logger->warning(
                sprintf(
                    'DigitalSignature: audit trail certificate fetch failed for document %d (%s): %s',
                    $id,
                    get_class($e),
                    $e->getMessage()
                )
            );
        }

        try {
            $this->auditTrailRepository->save($auditTrail);
        } catch (CouldNotSaveException $saveException) {
            $this->logger->warning(
                sprintf(
                    'DigitalSignature: audit trail save failed for document %d after fetch error: %s',
                    $id,
                    $saveException->getMessage()
                )
            );
        }
    }
}
