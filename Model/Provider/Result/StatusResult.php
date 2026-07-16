<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Provider\Result;

/**
 * Stato di un processo di firma letto dal provider (valore grezzo,
 * la mappatura sugli stati interni avviene a parte).
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
