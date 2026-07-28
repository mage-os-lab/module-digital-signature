<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Exception;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * Error from a signature connector. Distinguishes errors that are worth
 * retrying (network, 5xx) from permanent ones (configuration, invalid data).
 */
class ProviderException extends LocalizedException
{
    private bool $retryable = false;

    public static function retryable(Phrase $phrase, ?\Exception $cause = null): self
    {
        $exception = new self($phrase, $cause);
        $exception->retryable = true;

        return $exception;
    }

    public static function permanent(Phrase $phrase, ?\Exception $cause = null): self
    {
        return new self($phrase, $cause);
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}
