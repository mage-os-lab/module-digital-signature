<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Provider\Result;

/**
 * Status of a signing process read from the provider (raw value,
 * the mapping to internal statuses happens separately).
 */
class StatusResult
{
    public function __construct(
        private readonly string $rawStatus,
        private readonly ?string $rawResponse = null
    ) {
    }

    public function getRawStatus(): string
    {
        return $this->rawStatus;
    }

    public function getRawResponse(): ?string
    {
        return $this->rawResponse;
    }
}
