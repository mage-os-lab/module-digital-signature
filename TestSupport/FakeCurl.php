<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\TestSupport;

use Magento\Framework\HTTP\Client\Curl;

/**
 * Doppio di test del client Curl Magento: registra le chiamate e restituisce
 * status/body configurabili, oppure lancia l'eccezione impostata (errore di
 * trasporto).
 */
class FakeCurl extends Curl
{
    /** @var array<string, string> */
    public array $headers = [];

    /** @var array<int|string, mixed> */
    public array $options = [];

    public string $lastMethod = '';
    public ?string $lastUrl = null;
    public ?string $lastPayload = null;

    public int $status = 200;
    public string $body = '';
    public ?\Exception $transportError = null;

    public function addHeader($name, $value)
    {
        $this->headers[(string)$name] = (string)$value;
    }

    public function setOption($name, $value)
    {
        $this->options[$name] = $value;
    }

    public function get($uri)
    {
        if ($this->transportError) {
            throw $this->transportError;
        }
        $this->lastMethod = 'GET';
        $this->lastUrl = (string)$uri;
    }

    public function post($uri, $params)
    {
        if ($this->transportError) {
            throw $this->transportError;
        }
        $this->lastMethod = 'POST';
        $this->lastUrl = (string)$uri;
        $this->lastPayload = is_string($params) ? $params : null;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getBody(): string
    {
        return $this->body;
    }
}
