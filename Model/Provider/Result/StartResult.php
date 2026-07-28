<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Provider\Result;

/**
 * Result of starting a signing process with the provider.
 */
class StartResult
{
    public function __construct(
        private readonly string $processId,
        private readonly ?string $rawResponse = null
    ) {
    }

    public function getProcessId(): string
    {
        return $this->processId;
    }

    public function getRawResponse(): ?string
    {
        return $this->rawResponse;
    }
}
