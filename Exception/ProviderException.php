<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Exception;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * Errore di un connettore di firma. Distingue gli errori che ha senso
 * ritentare (rete, 5xx) da quelli permanenti (configurazione, dati invalidi).
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
