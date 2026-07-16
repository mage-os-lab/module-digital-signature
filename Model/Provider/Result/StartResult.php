<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Provider\Result;

/**
 * Esito dell'avvio di un processo di firma presso il provider.
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
