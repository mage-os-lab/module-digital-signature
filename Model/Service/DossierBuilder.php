<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Service;

use MageOS\DigitalSignature\Api\AuditTrailRepositoryInterface;
use MageOS\DigitalSignature\Api\Data\AuditTrailInterface;
use MageOS\DigitalSignature\Api\Data\DocumentInterface;
use MageOS\DigitalSignature\Api\DocumentRepositoryInterface;
use MageOS\DigitalSignature\Api\DocumentStorageInterface;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;
use ZipArchive;

/**
 * Builds a ZIP dossier (PDF + provider certificate + manifest.json) for a
 * batch of documents into a file chosen by the caller, for the "Export
 * dossier" grid mass action and the queued dossier export consumer.
 * A document that can't be found, or whose PDF/certificate can't be read,
 * never fails the whole batch — it gets a manifest entry explaining why.
 */
class DossierBuilder
{
    public function __construct(
        private readonly DocumentRepositoryInterface $documentRepository,
        private readonly DocumentStorageInterface $storage,
        private readonly AuditTrailRepositoryInterface $auditTrailRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Number of documents after which the archive is closed and reopened, so
     * ZipArchive writes the buffered contents to disk: peak memory stays at
     * about FLUSH_EVERY documents whatever the selection size.
     */
    public const FLUSH_EVERY = 20;

    /**
     * Writes the dossier ZIP to $targetPath (absolute). $onProgress, if given,
     * is called with the number of documents processed so far after each one;
     * it may throw (e.g. DossierExportAbortedException) to stop the build. On
     * any failure the partial file is removed and the exception rethrown.
     *
     * @param int[] $documentIds
     * @param string|null $generatedBy admin username that triggered the export, for the
     *        manifest's provenance fields; null when not known
     * @param callable(int):void|null $onProgress
     */
    public function buildToFile(
        array $documentIds,
        string $targetPath,
        ?string $generatedBy = null,
        ?callable $onProgress = null
    ): void {
        $zip = new ZipArchive();
        $opened = false;

        try {
            $this->openArchive($zip, $targetPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $opened = true;

            $manifestEntries = [];
            $processed = 0;
            foreach ($documentIds as $documentId) {
                $manifestEntries[] = $this->addDocument($zip, (int)$documentId);
                $processed++;

                if ($processed % self::FLUSH_EVERY === 0) {
                    $opened = false;
                    $this->closeArchive($zip);
                    // CREATE, not 0: closing an archive with no entries writes no file
                    $this->openArchive($zip, $targetPath, ZipArchive::CREATE);
                    $opened = true;
                }

                if ($onProgress !== null) {
                    $onProgress($processed);
                }
            }

            $manifestJson = json_encode(
                [
                    'generated_at' => (new \DateTime())->format(\DateTime::ATOM),
                    'generated_by' => $generatedBy,
                    'documents' => $manifestEntries,
                ],
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            );

            $zip->addFromString('manifest.json', $manifestJson);
            $opened = false;
            $this->closeArchive($zip);
        } catch (\Throwable $e) {
            if ($opened) {
                try {
                    $zip->close();
                } catch (\Throwable $closeException) {
                    // Cleanup must never itself throw and mask the real underlying error.
                    $this->logger->warning(
                        'DigitalSignature: dossier export failed to close the ZIP archive during cleanup: '
                        . $closeException->getMessage()
                    );
                }
            }
            if (file_exists($targetPath)) {
                // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- cleanup must not mask the real error
                @unlink($targetPath);
            }

            throw $e;
        }
    }

    private function openArchive(ZipArchive $zip, string $path, int $flags): void
    {
        if ($zip->open($path, $flags) !== true) {
            throw new \RuntimeException('Unable to open the dossier ZIP archive.');
        }
    }

    private function closeArchive(ZipArchive $zip): void
    {
        if ($zip->close() !== true) {
            throw new \RuntimeException('Unable to write the dossier ZIP archive.');
        }
    }

    private function addDocument(ZipArchive $zip, int $documentId): array
    {
        try {
            $document = $this->documentRepository->getById($documentId);
        } catch (NoSuchEntityException $e) {
            return $this->notFoundEntry($documentId, 'document_not_found');
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf(
                'DigitalSignature: dossier export could not resolve document #%d: %s',
                $documentId,
                $e->getMessage()
            ));

            return $this->notFoundEntry($documentId, 'resolution_failed');
        }

        $entry = [
            'document_id' => $documentId,
            'order_id' => $document->getOrderId(),
            'status' => $document->getStatus(),
            'provider_code' => $document->getProviderCode(),
            'hash_before_signing' => null,
            'hash_after_signing' => null,
            'transaction_id' => null,
            'pdf_included' => false,
            'certificate_included' => false,
            'pdf_unavailable_reason' => null,
            'certificate_unavailable_reason' => null,
        ];

        $this->addPdf($zip, $document, $entry);

        try {
            $this->addCertificate($zip, $document, $entry);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf(
                'DigitalSignature: dossier export could not resolve audit trail for document #%d: %s',
                $documentId,
                $e->getMessage()
            ));
            $entry['certificate_unavailable_reason'] = 'resolution_failed';
        }

        return $entry;
    }

    /**
     * Normalized "document not found / not resolvable" manifest entry, using the same
     * shape as every other entry so consumers never need to special-case it.
     */
    private function notFoundEntry(int $documentId, string $reason): array
    {
        return [
            'document_id' => $documentId,
            'order_id' => null,
            'status' => null,
            'provider_code' => null,
            'hash_before_signing' => null,
            'hash_after_signing' => null,
            'transaction_id' => null,
            'pdf_included' => false,
            'certificate_included' => false,
            'pdf_unavailable_reason' => $reason,
            'certificate_unavailable_reason' => $reason,
        ];
    }

    private function addPdf(ZipArchive $zip, DocumentInterface $document, array &$entry): void
    {
        $signedPath = $document->getSignedPdfPath();
        $relativePath = $signedPath ?: $document->getPdfPath();
        $fileName = $signedPath ? 'signed.pdf' : 'document.pdf';

        if (!$relativePath) {
            $entry['pdf_unavailable_reason'] = $document->getPurgedAt() !== null
                ? 'purged_by_retention'
                : 'not_generated';

            return;
        }

        try {
            if (!$this->storage->exists($relativePath)) {
                $entry['pdf_unavailable_reason'] = 'storage_read_failed';

                return;
            }
            $content = $this->storage->read($relativePath);
        } catch (FileSystemException $e) {
            $this->logger->warning(sprintf(
                'DigitalSignature: dossier export could not read PDF for document #%d: %s',
                $document->getDocumentId(),
                $e->getMessage()
            ));
            $entry['pdf_unavailable_reason'] = 'storage_read_failed';

            return;
        }

        $zip->addFromString(sprintf('document-%d/%s', $document->getDocumentId(), $fileName), $content);
        $entry['pdf_included'] = true;
    }

    private function addCertificate(ZipArchive $zip, DocumentInterface $document, array &$entry): void
    {
        $auditTrail = $this->auditTrailRepository->getByDocumentId((int)$document->getDocumentId());
        if ($auditTrail === null) {
            $entry['certificate_unavailable_reason'] = 'audit_trail_disabled';

            return;
        }

        $entry['hash_before_signing'] = $auditTrail->getHashBeforeSigning();
        $entry['hash_after_signing'] = $auditTrail->getHashAfterSigning();
        $entry['transaction_id'] = $auditTrail->getTransactionId();

        $certificatePath = $auditTrail->getCertificatePath();
        if ($auditTrail->getFetchStatus() !== AuditTrailInterface::FETCH_STATUS_FETCHED || !$certificatePath) {
            $entry['certificate_unavailable_reason'] = match ($auditTrail->getFetchStatus()) {
                AuditTrailInterface::FETCH_STATUS_UNAVAILABLE => 'provider_not_supported',
                AuditTrailInterface::FETCH_STATUS_ERROR => 'fetch_error',
                // Fetched, but certificate_path cleared by RetentionCleanup
                AuditTrailInterface::FETCH_STATUS_FETCHED => 'purged_by_retention',
                default => 'fetch_pending',
            };

            return;
        }

        try {
            if (!$this->storage->exists($certificatePath)) {
                $entry['certificate_unavailable_reason'] = 'storage_read_failed';

                return;
            }
            $content = $this->storage->read($certificatePath);
        } catch (FileSystemException $e) {
            $this->logger->warning(sprintf(
                'DigitalSignature: dossier export could not read certificate for document #%d: %s',
                $document->getDocumentId(),
                $e->getMessage()
            ));
            $entry['certificate_unavailable_reason'] = 'storage_read_failed';

            return;
        }

        $zip->addFromString(sprintf('document-%d/certificate.pdf', $document->getDocumentId()), $content);
        $entry['certificate_included'] = true;
    }
}
