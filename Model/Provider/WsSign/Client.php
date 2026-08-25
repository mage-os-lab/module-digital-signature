<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Provider\WsSign;

use MageOS\DigitalSignature\Exception\ProviderException;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * HTTP client for the WsSign APIs (see docs/wssign-api.md).
 *
 * Hardening: TLS verify enabled, no redirect following (a malicious redirect
 * would hijack the bearer token), tight timeouts, JSON responses structurally
 * validated before use, no tokens in error messages.
 */
class Client
{
    private const CONNECT_TIMEOUT = 10;
    private const TIMEOUT = 60;

    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly Json $json
    ) {
    }

    /**
     * @throws ProviderException
     */
    public function fetchToken(string $platformUrl, string $tenant, string $username, string $password): string
    {
        $curl = $this->createCurl();
        $curl->addHeader('Content-Type', 'application/x-www-form-urlencoded');
        $url = rtrim($platformUrl, '/') . '/api/token/' . rawurlencode($tenant);
        $payload = http_build_query([
            'grant_type' => 'password',
            'client_id' => 'wsign-api',
            'username' => $username,
            'password' => $password,
        ], '', '&', PHP_QUERY_RFC3986);

        $body = $this->execute($curl, 'POST', $url, $payload, 'token');
        $data = $this->decodeJson($body, 'token');
        $token = $data['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw ProviderException::permanent(
                __('WsSign: authentication failed, check the credentials and tenant.')
            );
        }

        return $token;
    }

    /**
     * @return string GUID of the created document
     * @throws ProviderException
     */
    public function uploadDocument(
        string $platformUrl,
        string $token,
        string $owner,
        string $fileName,
        string $pdfContent
    ): string {
        $payload = $this->json->serialize([
            'owner' => $owner,
            'files' => [
                ['name' => $fileName, 'file' => base64_encode($pdfContent)],
            ],
        ]);

        $curl = $this->createJsonCurl($token);
        $url = rtrim($platformUrl, '/') . '/api/v4/consumer/document';
        $body = $this->execute($curl, 'POST', $url, $payload, 'upload');
        $data = $this->decodeJson($body, 'upload');

        if (($data['message'] ?? '') !== 'DOCUMENT_ADDED') {
            throw ProviderException::permanent(
                __('WsSign: document upload rejected (%1).', (string)($data['message'] ?? 'unknown response'))
            );
        }
        $guid = $data['data']['documents'][0]['guid'] ?? null;
        if (!is_string($guid) || $guid === '') {
            throw ProviderException::permanent(__('WsSign: GUID missing from the upload response.'));
        }

        return $guid;
    }

    /**
     * @param array<string, mixed> $sharePayload
     * @return array<string, mixed> decoded response
     * @throws ProviderException
     */
    public function shareDocument(string $platformUrl, string $token, string $guid, array $sharePayload): array
    {
        $curl = $this->createJsonCurl($token);
        $url = rtrim($platformUrl, '/') . '/api/v6/consumer/document/' . rawurlencode($guid) . '/share';
        $body = $this->execute($curl, 'POST', $url, $this->json->serialize($sharePayload), 'share');

        return $this->decodeJson($body, 'share');
    }

    /**
     * @return array<string, mixed>|null null = 404 (expired/cancelled on the provider side)
     * @throws ProviderException
     */
    public function getDocument(string $platformUrl, string $token, string $guid): ?array
    {
        $curl = $this->createJsonCurl($token);
        $url = rtrim($platformUrl, '/') . '/api/v2/consumer/document/' . rawurlencode($guid);
        $body = $this->execute($curl, 'GET', $url, null, 'status', [404]);
        if ($body === null) {
            return null;
        }

        return $this->decodeJson($body, 'status');
    }

    /**
     * @return string binary content of the signed PDF
     * @throws ProviderException
     */
    public function downloadDocument(string $platformUrl, string $token, string $guid): string
    {
        $curl = $this->createCurl();
        $curl->addHeader('Authorization', 'Bearer ' . $token);
        $curl->addHeader('Accept', 'application/pdf');
        $url = rtrim($platformUrl, '/') . '/api/v2/consumer/document/' . rawurlencode($guid) . '/download';
        $body = $this->execute($curl, 'GET', $url, null, 'download');

        // The provider is a trust boundary: validate that it is really a PDF
        if (!str_starts_with($body, '%PDF')) {
            throw ProviderException::permanent(
                __('WsSign: the downloaded content is not a valid PDF.')
            );
        }

        return $body;
    }

    /**
     * Does not fail on 404 (document already removed).
     *
     * @throws ProviderException
     */
    public function deleteDocument(string $platformUrl, string $token, string $guid): void
    {
        $curl = $this->createCurl();
        $curl->addHeader('Authorization', 'Bearer ' . $token);
        $url = rtrim($platformUrl, '/') . '/api/v2/consumer/document/' . rawurlencode($guid);
        $this->execute($curl, 'DELETE', $url, null, 'delete', [404]);
    }

    private function createCurl(): Curl
    {
        $curl = $this->curlFactory->create();
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
        $curl->setOption(CURLOPT_TIMEOUT, self::TIMEOUT);
        $curl->setOption(CURLOPT_FOLLOWLOCATION, false);
        $curl->setOption(CURLOPT_SSL_VERIFYPEER, true);
        $curl->setOption(CURLOPT_SSL_VERIFYHOST, 2);

        return $curl;
    }

    private function createJsonCurl(string $token): Curl
    {
        $curl = $this->createCurl();
        $curl->addHeader('Content-Type', 'application/json');
        $curl->addHeader('Authorization', 'Bearer ' . $token);

        return $curl;
    }

    /**
     * @param int[] $allowedErrorCodes HTTP error codes not to treat as an exception (returns null)
     * @throws ProviderException
     */
    private function execute(
        Curl $curl,
        string $method,
        string $url,
        ?string $payload,
        string $operation,
        array $allowedErrorCodes = []
    ): ?string {
        try {
            if ($method === 'POST') {
                $curl->post($url, $payload ?? '');
            } elseif ($method === 'DELETE') {
                $curl->setOption(CURLOPT_CUSTOMREQUEST, 'DELETE');
                $curl->post($url, '');
            } else {
                $curl->get($url);
            }
        } catch (\Exception $e) {
            // Transport error (DNS, timeout, TLS): retrying makes sense
            throw ProviderException::retryable(
                __('WsSign: network error during "%1": %2', $operation, $e->getMessage()),
                $e
            );
        }

        $status = $curl->getStatus();
        if ($status >= 200 && $status < 300) {
            return $curl->getBody();
        }
        if (in_array($status, $allowedErrorCodes, true)) {
            return null;
        }
        if ($status >= 500 || $status === 429) {
            throw ProviderException::retryable(
                __('WsSign: temporary service error during "%1" (HTTP %2).', $operation, $status)
            );
        }

        // 4xx: wrong configuration or data, retrying does not help
        throw ProviderException::permanent(
            __('WsSign: request rejected during "%1" (HTTP %2).', $operation, $status)
        );
    }

    /**
     * @return array<string, mixed>
     * @throws ProviderException
     */
    private function decodeJson(string $body, string $operation): array
    {
        try {
            $data = $this->json->unserialize($body);
        } catch (\InvalidArgumentException $e) {
            throw ProviderException::permanent(
                __('WsSign: non-JSON response during "%1".', $operation),
                $e
            );
        }
        if (!is_array($data)) {
            throw ProviderException::permanent(__('WsSign: unexpected response structure during "%1".', $operation));
        }

        return $data;
    }
}
