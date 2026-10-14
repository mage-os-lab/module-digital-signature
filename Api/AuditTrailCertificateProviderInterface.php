<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Api;

use MageOS\DigitalSignature\Api\Data\DocumentInterface;
use MageOS\DigitalSignature\Exception\ProviderException;
use MageOS\DigitalSignature\Model\Provider\Result\AuditTrailCertificateResult;

/**
 * Implemented ONLY by providers that expose an official signing
 * certificate/audit report (DocuSign, Adobe Sign). Providers that don't
 * (WsSign, Dummy) simply do not implement this interface; callers must
 * check `instanceof` rather than calling this unconditionally.
 */
interface AuditTrailCertificateProviderInterface
{
    /**
     * @throws ProviderException retryable on transient/not-ready-yet errors, permanent otherwise
     */
    public function fetchCertificate(DocumentInterface $document): AuditTrailCertificateResult;
}
