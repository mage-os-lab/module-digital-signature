<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Provider\AdobeSign;

use MageOS\DigitalSignature\Exception\ProviderException;
use MageOS\DigitalSignature\Model\Provider\ProviderConfig;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * HTTP client for the Adobe Acrobat Sign REST v6 APIs.
 * Unlike DocuSign (JWT Server-to-Server), Adobe Sign uses a 3-legged
 * OAuth flow: the initial refresh token must be obtained once via
 * interactive administrator consent and saved in the configuration.
 * The client is limited to renewing the access token from that refresh token.
 */
class Client
{
    private const CONNECT_TIMEOUT = 10;
    private const TIMEOUT = 60;
    private const CACHE_PREFIX = 'adobesign_token_';
    private const PROVIDER_CODE = 'adobesign';

    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly Json $json,
        private readonly CacheInterface $cache,
        private readonly ProviderConfig $config
    ) {
    }

    /**
     * Uploads the PDF as a transient document and creates the agreement in "IN_PROCESS" state.
     *
     * @throws ProviderException
     */
    public function createAgreement(int $storeId, array $agreementData, string $fileName, string $pdfContent): array
    {
        $session = $this->getSession($storeId);
        $transientDocumentId = $this->uploadTransientDocument($storeId, $session, $fileName, $pdfContent);

        $agreementData['fileInfos'] = [['transientDocumentId' => $transientDocumentId]];

        $url = $session['api_access_point'] . '/api/rest/v6/agreements';
        $curl = $this->createCurlWithAuth($session['access_token']);
        $curl->addHeader('Content-Type', 'application/json');

        $payload = $this->json->serialize($agreementData);
        $body = $this->execute($curl, 'POST', $url, $payload, 'creazione accordo');

        return $this->decodeJson($body, 'creazione accordo');
    }

    /**
     * Retrieves the current status of the agreement.
     *
     * @throws ProviderException
     */
    public function getAgreementStatus(int $storeId, string $agreementId): array
    {
        $session = $this->getSession($storeId);
        $url = $session['api_access_point'] . '/api/rest/v6/agreements/' . rawurlencode($agreementId);

        $curl = $this->createCurlWithAuth($session['access_token']);
        $body = $this->execute($curl, 'GET', $url, null, 'reading agreement status');

        return $this->decodeJson($body, 'reading agreement status');
    }

    /**
     * Downloads the combined PDF (document + signature certificate) of the agreement.
     *
     * @throws ProviderException
     */
    public function downloadCombinedDocument(int $storeId, string $agreementId): string
    {
        $session = $this->getSession($storeId);
        $url = $session['api_access_point'] . '/api/rest/v6/agreements/'
            . rawurlencode($agreementId) . '/combinedDocument';

        $curl = $this->createCurlWithAuth($session['access_token']);
        $curl->addHeader('Accept', 'application/pdf');

        $body = $this->execute($curl, 'GET', $url, null, 'downloading signed PDF');
        if ($body === null || $body === '') {
            throw ProviderException::permanent(__('Adobe Acrobat Sign: downloaded PDF content is empty.'));
        }

        return $body;
    }

    /**
     * Cancels (CANCELLED) the active agreement.
     *
     * @throws ProviderException
     */
    public function cancelAgreement(int $storeId, string $agreementId, string $comment): void
    {
        $session = $this->getSession($storeId);
        $url = $session['api_access_point'] . '/api/rest/v6/agreements/' . rawurlencode($agreementId) . '/state';

        $curl = $this->createCurlWithAuth($session['access_token']);
        $curl->addHeader('Content-Type', 'application/json');

        $payload = $this->json->serialize([
            'state' => 'CANCELLED',
            'agreementCancellationInfo' => [
                'comment' => $comment,
                'notifyOtherParties' => false
            ]
        ]);

        $this->execute($curl, 'PUT', $url, $payload, 'cancelling agreement', [404]);
    }

    /**
     * Gets the session (access_token, api_access_point) from the cache or renews
     * the access token from the configured refresh token.
     *
     * @throws ProviderException
     */
    private function getSession(int $storeId): array
    {
        $cacheKey = self::CACHE_PREFIX . $storeId;
        $cachedData = $this->cache->load($cacheKey);
        if ($cachedData) {
            try {
                $session = $this->json->unserialize($cachedData);
                if (is_array($session) && isset($session['access_token'], $session['api_access_point'])) {
                    return $session;
                }
            } catch (\Exception) {
                // If the cache is corrupted, regenerate it
            }
        }

        $session = $this->refreshAccessToken($storeId);
        // Access token typically valid for 1h: cache for 55 minutes
        $this->cache->save($this->json->serialize($session), $cacheKey, [], 3300);

        return $session;
    }

    /**
     * Renews the access token starting from the configured refresh token.
     *
     * @throws ProviderException
     */
    private function refreshAccessToken(int $storeId): array
    {
        $clientId = $this->config->get(self::PROVIDER_CODE, 'client_id', $storeId);
        $clientSecret = $this->config->getSecret(self::PROVIDER_CODE, 'client_secret', $storeId);
        $refreshToken = $this->config->getSecret(self::PROVIDER_CODE, 'refresh_token', $storeId);
        $apiAccessPoint = rtrim((string)$this->config->get(self::PROVIDER_CODE, 'api_access_point', $storeId), '/');

        if (empty($clientId) || empty($clientSecret) || empty($refreshToken) || empty($apiAccessPoint)) {
            throw ProviderException::permanent(
                __(
                    'Adobe Acrobat Sign: incomplete configuration (missing Client ID, Client Secret, '
                    . 'Refresh Token, or API Access Point).'
                )
            );
        }

        $curl = $this->createCurl();
        $curl->addHeader('Content-Type', 'application/x-www-form-urlencoded');
        $url = $apiAccessPoint . '/oauth/v2/refresh';
        $payload = http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $clientId,
            'client_secret' => $clientSecret
        ]);

        $body = $this->execute($curl, 'POST', $url, $payload, 'renewing access token');
        $data = $this->decodeJson($body, 'renewing access token');

        $accessToken = $data['access_token'] ?? null;
        if (!$accessToken) {
            throw ProviderException::permanent(
                __('Adobe Acrobat Sign: valid refresh response but missing access token.')
            );
        }

        return [
            'access_token' => $accessToken,
            'api_access_point' => $apiAccessPoint
        ];
    }

    /**
     * Uploads the PDF as a transient document (multipart/form-data) and returns its id.
     *
     * @throws ProviderException
     */
    private function uploadTransientDocument(int $storeId, array $session, string $fileName, string $pdfContent): string
    {
        $boundary = '----MageOSDigitalSignature' . bin2hex(random_bytes(16));
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"File-Name\"\r\n\r\n"
            . $fileName . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"File\"; filename=\"{$fileName}\"\r\n"
            . "Content-Type: application/pdf\r\n\r\n"
            . $pdfContent . "\r\n"
            . "--{$boundary}--\r\n";

        $url = $session['api_access_point'] . '/api/rest/v6/transientDocuments';
        $curl = $this->createCurlWithAuth($session['access_token']);
        $curl->addHeader('Content-Type', 'multipart/form-data; boundary=' . $boundary);

        $responseBody = $this->execute($curl, 'POST', $url, $body, 'uploading transient document');
        $data = $this->decodeJson($responseBody, 'uploading transient document');

        $transientDocumentId = $data['transientDocumentId'] ?? null;
        if (!$transientDocumentId) {
            throw ProviderException::permanent(
                __('Adobe Acrobat Sign: document upload succeeded but is missing transientDocumentId.')
            );
        }

        return $transientDocumentId;
    }

    private function createCurl(): Curl
    {
        $curl = $this->curlFactory->create();
        $curl->setOption(CURLOPT_RETURNTRANSFER, true);
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
        $curl->setOption(CURLOPT_TIMEOUT, self::TIMEOUT);
        $curl->setOption(CURLOPT_SSL_VERIFYPEER, true);
        $curl->setOption(CURLOPT_SSL_VERIFYHOST, 2);
        $curl->setOption(CURLOPT_FOLLOWLOCATION, false);

        return $curl;
    }

    private function createCurlWithAuth(string $accessToken): Curl
    {
        $curl = $this->createCurl();
        $curl->addHeader('Authorization', 'Bearer ' . $accessToken);

        return $curl;
    }

    /**
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
            } elseif ($method === 'PUT') {
                $curl->setOption(CURLOPT_CUSTOMREQUEST, 'PUT');
                $curl->post($url, $payload ?? '');
            } else {
                $curl->get($url);
            }
        } catch (\Exception $e) {
            throw ProviderException::retryable(
                __('Adobe Acrobat Sign: network error during "%1": %2', $operation, $e->getMessage()),
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
                __('Adobe Acrobat Sign: temporary service error during "%1" (HTTP %2).', $operation, $status)
            );
        }

        throw ProviderException::permanent(
            __(
                'Adobe Acrobat Sign: richiesta rifiutata durante "%1" (HTTP %2). Risposta: %3',
                $operation,
                $status,
                $curl->getBody()
            )
        );
    }

    /**
     * @throws ProviderException
     */
    private function decodeJson(string $body, string $operation): array
    {
        try {
            $data = $this->json->unserialize($body);
        } catch (\InvalidArgumentException $e) {
            throw ProviderException::permanent(
                __('Adobe Acrobat Sign: non-JSON response during "%1".', $operation),
                $e
            );
        }
        if (!is_array($data)) {
            throw ProviderException::permanent(
                __('Adobe Acrobat Sign: struttura risposta inattesa durante "%1".', $operation)
            );
        }

        return $data;
    }
}
