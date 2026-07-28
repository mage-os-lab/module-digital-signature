<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Api;

use MageOS\DigitalSignature\Api\Data\DocumentInterface;
use MageOS\DigitalSignature\Exception\ProviderException;
use MageOS\DigitalSignature\Model\Provider\Result\StartResult;
use MageOS\DigitalSignature\Model\Provider\Result\StatusResult;

/**
 * Contract for digital signature connectors (pluggable pattern, similar to payment methods).
 *
 * Connectors do NOT modify the document and do NOT access the storage: they receive
 * the contents as binary strings and return results; persistence is the
 * responsibility of the calling service.
 */
interface SignProviderInterface
{
    public function getCode(): string;

    public function getLabel(): string;

    public function isEnabled(?int $storeId = null): bool;

    /**
     * Uploads the PDF to the provider and starts the signing process.
     *
     * @param string $pdfContent binary content of the generated PDF
     * @param string $callbackToken plaintext token to include in the callback URL
     * @throws ProviderException
     */
    public function start(DocumentInterface $document, string $pdfContent, string $callbackToken): StartResult;

    /**
     * Queries the process status from the provider (polling).
     *
     * @throws ProviderException
     */
    public function fetchStatus(DocumentInterface $document): StatusResult;

    /**
     * Downloads the signed PDF (binary content).
     *
     * @throws ProviderException
     */
    public function downloadSignedPdf(DocumentInterface $document): string;

    /**
     * Cancels/deletes the process at the provider (for regeneration/resend).
     * Must not fail if the process no longer exists.
     *
     * @throws ProviderException
     */
    public function cancel(DocumentInterface $document): void;

    /**
     * Maps the provider's raw status to an internal status
     * (Model\Document\Status::*). Null = unknown status (to be logged).
     */
    public function mapStatus(string $providerStatus): ?string;
}
