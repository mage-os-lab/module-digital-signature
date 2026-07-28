<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Webhook;

class DeliveryResult
{
    private function __construct(
        private readonly bool $success,
        private readonly ?string $error,
        private readonly bool $retryable = true
    ) {
    }

    public static function success(): self
    {
        return new self(true, null);
    }

    /**
     * @param string $error
     * @param bool $retryable false = permanent error, useless to retry
     * @return self
     */
    public static function failure(string $error, bool $retryable = true): self
    {
        return new self(false, $error, $retryable);
    }

    /**
     * A permanent failure (e.g. 401/404/410) must not be retried: it would burn
     * the remaining attempts with no chance of success.
     */
    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getError(): ?string
    {
        return $this->error;
    }
}
