<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Provider\Docusign;

use MageOS\DigitalSignature\Exception\ProviderException;
use MageOS\DigitalSignature\Model\Provider\ProviderConfig;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * HTTP client for the DocuSign REST v2.1 APIs.
 * Handles JWT Server-to-Server authentication and token caching.
 */
class Client
{
    private const CONNECT_TIMEOUT = 10;
    private const TIMEOUT = 60;
    private const CACHE_PREFIX = 'docusign_jwt_';

    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly Json $json,
        private readonly CacheInterface $cache,
        private readonly ProviderConfig $config
    ) {
    }

    /**
     * Starts the envelope on DocuSign.
     *
     * @throws ProviderException
     */
    public function createEnvelope(int $storeId, array $envelopeData): array
    {
        $session = $this->getSession($storeId);
        $url = $session['base_url'] . '/restapi/v2.1/accounts/' . $session['account_id'] . '/envelopes';

        $curl = $this->createCurlWithAuth($session['access_token']);
        $curl->addHeader('Content-Type', 'application/json');

        $payload = $this->json->serialize($envelopeData);
        $body = $this->execute($curl, 'POST', $url, $payload, 'envelope creation');

        return $this->decodeJson($body, 'envelope creation');
    }

    /**
     * Retrieves the current status of the envelope on DocuSign.
     *
     * @throws ProviderException
     */
    public function getEnvelopeStatus(int $storeId, string $envelopeId): array
    {
        $session = $this->getSession($storeId);
        $url = $session['base_url'] . '/restapi/v2.1/accounts/' . $session['account_id'] . '/envelopes/' . rawurlencode($envelopeId);

        $curl = $this->createCurlWithAuth($session['access_token']);
        $body = $this->execute($curl, 'GET', $url, null, 'envelope status reading');

        return $this->decodeJson($body, 'envelope status reading');
    }

    /**
     * Downloads the signed PDF associated with the envelope.
     *
     * @throws ProviderException
     */
    public function downloadDocument(int $storeId, string $envelopeId, string $documentId = '1'): string
    {
        $session = $this->getSession($storeId);
        $url = $session['base_url'] . '/restapi/v2.1/accounts/' . $session['account_id'] . '/envelopes/'
            . rawurlencode($envelopeId) . '/documents/' . rawurlencode($documentId);

        $curl = $this->createCurlWithAuth($session['access_token']);
        $curl->addHeader('Accept', 'application/pdf');

        $body = $this->execute($curl, 'GET', $url, null, 'downloading signed PDF');
        if ($body === null || $body === '') {
            throw ProviderException::permanent(__('DocuSign: downloaded PDF content is empty.'));
        }

        return $body;
    }

    /**
     * Voids the active envelope on DocuSign.
     *
     * @throws ProviderException
     */
    public function voidEnvelope(int $storeId, string $envelopeId, string $reason): void
    {
        $session = $this->getSession($storeId);
        $url = $session['base_url'] . '/restapi/v2.1/accounts/' . $session['account_id'] . '/envelopes/' . rawurlencode($envelopeId);

        $curl = $this->createCurlWithAuth($session['access_token']);
        $curl->addHeader('Content-Type', 'application/json');

        $payload = $this->json->serialize([
            'status' => 'voided',
            'voidedReason' => $reason
        ]);

        $this->execute($curl, 'PUT', $url, $payload, 'envelope voiding', [404]);
    }

    /**
     * Gets the active session (access_token, base_url, account_id), reading it from the cache
     * or performing the JWT login.
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
                if (is_array($session) && isset($session['access_token'], $session['base_url'], $session['account_id'])) {
                    return $session;
                }
            } catch (\Exception) {
                // If the cache is corrupted, regenerate it
            }
        }

        $session = $this->authenticateJwt($storeId);
        // Cache for 50 minutes (the JWT token lasts 60 minutes)
        $this->cache->save($this->json->serialize($session), $cacheKey, [], 3000);

        return $session;
    }

    /**
     * Authenticates via JWT Assertion.
     *
     * @throws ProviderException
     */
    private function authenticateJwt(int $storeId): array
    {
        $providerCode = \MageOS\DigitalSignature\Model\Provider\Docusign::CODE;
        $integrationKey = $this->config->get($providerCode, 'integration_key', $storeId);
        $userId = $this->config->get($providerCode, 'user_id', $storeId);
        $privateKeyPem = $this->config->getSecret($providerCode, 'private_key', $storeId);
        $environment = $this->config->get($providerCode, 'environment', $storeId) ?: 'demo';

        if (empty($integrationKey) || empty($userId) || empty($privateKeyPem)) {
            throw ProviderException::permanent(
                __('DocuSign: incomplete configuration (missing Integration Key, User ID or Private Key).')
            );
        }

        $authServer = ($environment === 'production') ? 'account.docusign.com' : 'account-d.docusign.com';
        
        try {
            $jwt = $this->generateJwt($integrationKey, $userId, $privateKeyPem, $authServer);
        } catch (\Exception $e) {
            throw ProviderException::permanent(
                __('DocuSign: error generating the JWT assertion: %1', $e->getMessage()),
                $e
            );
        }

        // 1. Request OAuth Access Token
        $curl = $this->createCurl();
        $curl->addHeader('Content-Type', 'application/x-www-form-urlencoded');
        $url = 'https://' . $authServer . '/oauth/token';
        $payload = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt
        ]);

        $tokenBody = $this->execute($curl, 'POST', $url, $payload, 'OAuth token request');
        $tokenData = $this->decodeJson($tokenBody, 'OAuth token request');
        $accessToken = $tokenData['access_token'] ?? null;

        if (!$accessToken) {
            throw ProviderException::permanent(
                __('DocuSign: valid OAuth response but missing access token.')
            );
        }

        // 2. Request Account Info to obtain base_url and account_id
        $curlUserInfo = $this->createCurl();
        $curlUserInfo->addHeader('Authorization', 'Bearer ' . $accessToken);
        $userInfoUrl = 'https://' . $authServer . '/oauth/userinfo';

        $userInfoBody = $this->execute($curlUserInfo, 'GET', $userInfoUrl, null, 'user info reading');
        $userInfoData = $this->decodeJson($userInfoBody, 'user info reading');

        $accounts = $userInfoData['accounts'] ?? [];
        if (!is_array($accounts) || empty($accounts)) {
            throw ProviderException::permanent(
                __('DocuSign: no account associated with the provided credentials.')
            );
        }

        // Look for the account configured by the admin, otherwise use the default one or the first
        $targetAccountId = $this->config->get($providerCode, 'account_id', $storeId);
        $selectedAccount = null;

        foreach ($accounts as $acc) {
            if ($targetAccountId && isset($acc['account_id']) && $acc['account_id'] === $targetAccountId) {
                $selectedAccount = $acc;
                break;
            }
            if (isset($acc['is_default']) && $acc['is_default']) {
                $selectedAccount = $acc;
            }
        }

        if (!$selectedAccount) {
            $selectedAccount = $accounts[0];
        }

        $accountId = $selectedAccount['account_id'] ?? null;
        $baseUri = $selectedAccount['base_uri'] ?? null;

        if (!$accountId || !$baseUri) {
            throw ProviderException::permanent(
                __('DocuSign: dati dell\'account selezionato incompleti o malformati.')
            );
        }

        return [
            'access_token' => $accessToken,
            'base_url' => rtrim($baseUri, '/'),
            'account_id' => $accountId
        ];
    }

    /**
     * Generates a JWT token (RS256) for authentication with DocuSign.
     */
    private function generateJwt(
        string $integrationKey,
        string $userId,
        string $privateKeyPem,
        string $audience
    ): string {
        $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
        $payload = json_encode([
            'iss' => $integrationKey,
            'sub' => $userId,
            'aud' => $audience,
            'iat' => time(),
            'exp' => time() + 3600,
            'scope' => 'signature impersonation'
        ]);

        $base64UrlHeader = $this->base64UrlEncode($header);
        $base64UrlPayload = $this->base64UrlEncode($payload);

        $signatureInput = $base64UrlHeader . '.' . $base64UrlPayload;

        $privateKey = openssl_pkey_get_private($privateKeyPem);
        if (!$privateKey) {
            throw new \InvalidArgumentException('Chiave privata RSA non valida o non in formato PEM.');
        }

        $signature = '';
        if (!openssl_sign($signatureInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('OpenSSL signing of the JWT token failed.');
        }

        $base64UrlSignature = $this->base64UrlEncode($signature);

        return $signatureInput . '.' . $base64UrlSignature;
    }

    private function base64UrlEncode(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    private function createCurl(): Curl
    {
        $curl = $this->curlFactory->create();
        $curl->setOption(CURLOPT_RETURNTRANSFER, true);
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
        $curl->setOption(CURLOPT_TIMEOUT, self::TIMEOUT);
        $curl->setOption(CURLOPT_SSL_VERIFYPEER, true);
        $curl->setOption(CURLOPT_SSL_VERIFYHOST, 2);
        // Do not follow redirects for security (avoids token hijacking)
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
            } elseif ($method === 'DELETE') {
                $curl->setOption(CURLOPT_CUSTOMREQUEST, 'DELETE');
                $curl->post($url, '');
            } else {
                $curl->get($url);
            }
        } catch (\Exception $e) {
            throw ProviderException::retryable(
                __('DocuSign: network error during "%1": %2', $operation, $e->getMessage()),
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
                __('DocuSign: temporary service error during "%1" (HTTP %2).', $operation, $status)
            );
        }

        // 4xx: permanent error (wrong credentials, malformed payload, etc.)
        throw ProviderException::permanent(
            __('DocuSign: request rejected during "%1" (HTTP %2). Response: %3', $operation, $status, $curl->getBody())
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
                __('DocuSign: non-JSON response during "%1".', $operation),
                $e
            );
        }
        if (!is_array($data)) {
            throw ProviderException::permanent(__('DocuSign: unexpected response structure during "%1".', $operation));
        }

        return $data;
    }
}
