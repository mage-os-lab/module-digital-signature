<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Provider\Result;

/**
 * Result of fetching the provider's official signing certificate/report.
 */
class AuditTrailCertificateResult
{
    public function __construct(
        private readonly string $certificateContent,
        private readonly ?string $signerIp = null,
        private readonly ?string $authenticationMethod = null,
        private readonly ?string $transactionId = null
    ) {
    }

    public function getCertificateContent(): string
    {
        return $this->certificateContent;
    }

    public function getSignerIp(): ?string
    {
        return $this->signerIp;
    }

    public function getAuthenticationMethod(): ?string
    {
        return $this->authenticationMethod;
    }

    public function getTransactionId(): ?string
    {
        return $this->transactionId;
    }
}
