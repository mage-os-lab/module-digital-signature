<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Webhook;

use Magento\Framework\HTTP\Client\CurlFactory;

/**
 * HTTP delivery of outbound webhooks: same hardening already used for the
 * calls to signature providers (TLS verify, no cross-host redirect,
 * tight timeouts) — here the direction is reversed (Magento calls out),
 * but the risks of a malicious redirect hijacking the HMAC signature are
 * similar.
 */
class Client
{
    private const CONNECT_TIMEOUT = 5;
    private const TIMEOUT = 15;

    /** 4xx status codes that remain retryable anyway (server-side timeout, rate limit) */
    private const RETRYABLE_CLIENT_ERRORS = [408, 429];

    public function __construct(private readonly CurlFactory $curlFactory)
    {
    }

    public function deliver(string $url, string $payload, string $signatureHeader): DeliveryResult
    {
        $curl = $this->curlFactory->create();
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
        $curl->setOption(CURLOPT_TIMEOUT, self::TIMEOUT);
        $curl->setOption(CURLOPT_FOLLOWLOCATION, false);
        $curl->setOption(CURLOPT_SSL_VERIFYPEER, true);
        $curl->setOption(CURLOPT_SSL_VERIFYHOST, 2);
        // Defense in depth anti-SSRF: only http/https, even in redirects
        // (which are disabled above anyway).
        $curl->setOption(CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        $curl->setOption(CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        $curl->addHeader('Content-Type', 'application/json');
        $curl->addHeader('X-Signature', $signatureHeader);

        try {
            $curl->post($url, $payload);
        } catch (\Exception $e) {
            return DeliveryResult::failure('Errore di rete: ' . $e->getMessage());
        }

        $status = $curl->getStatus();
        if ($status >= 200 && $status < 300) {
            return DeliveryResult::success();
        }

        return DeliveryResult::failure(sprintf('HTTP %d', $status), $this->isRetryableStatus($status));
    }

    /**
     * 4xx (excluding 408/429) = permanent error from the receiver: retrying would
     * not change the outcome. Everything else (5xx, anomalous statuses) is retryable.
     */
    private function isRetryableStatus(int $status): bool
    {
        if ($status >= 400 && $status < 500) {
            return in_array($status, self::RETRYABLE_CLIENT_ERRORS, true);
        }

        return true;
    }
}
