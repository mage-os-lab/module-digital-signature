<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Provider\WsSign;

use MageOS\DigitalSignature\Exception\ProviderException;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Client HTTP per le API WsSign (vedi docs/wssign-api.md).
 *
 * Hardening: TLS verify attivo, nessun follow di redirect (un redirect malevolo
 * dirotterebbe il bearer token), timeout stretti, risposte JSON validate
 * strutturalmente prima dell'uso, niente token nei messaggi d'errore.
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
                __('WsSign: autenticazione fallita, verifica credenziali e tenant.')
            );
        }

        return $token;
    }

    /**
     * @return string GUID del documento creato
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
                __('WsSign: caricamento documento rifiutato (%1).', (string)($data['message'] ?? 'risposta sconosciuta'))
            );
        }
        $guid = $data['data']['documents'][0]['guid'] ?? null;
        if (!is_string($guid) || $guid === '') {
            throw ProviderException::permanent(__('WsSign: GUID non presente nella risposta di upload.'));
        }

        return $guid;
    }

    /**
     * @param array<string, mixed> $sharePayload
     * @return array<string, mixed> risposta decodificata
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
     * @return array<string, mixed>|null null = 404 (scaduto/cancellato lato provider)
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
     * @return string contenuto binario del PDF firmato
     * @throws ProviderException
     */
    public function downloadDocument(string $platformUrl, string $token, string $guid): string
    {
        $curl = $this->createCurl();
        $curl->addHeader('Authorization', 'Bearer ' . $token);
        $curl->addHeader('Accept', 'application/pdf');
        $url = rtrim($platformUrl, '/') . '/api/v2/consumer/document/' . rawurlencode($guid) . '/download';
        $body = $this->execute($curl, 'GET', $url, null, 'download');

        // Il provider è un confine di fiducia: validare che sia davvero un PDF
        if (!str_starts_with($body, '%PDF')) {
            throw ProviderException::permanent(
                __('WsSign: il contenuto scaricato non è un PDF valido.')
            );
        }

        return $body;
    }

    /**
     * Non fallisce su 404 (documento già rimosso).
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
     * @param int[] $allowedErrorCodes codici HTTP di errore da non trattare come eccezione (ritorna null)
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
            // Errore di trasporto (DNS, timeout, TLS): ha senso ritentare
            throw ProviderException::retryable(
                __('WsSign: errore di rete durante "%1": %2', $operation, $e->getMessage()),
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
                __('WsSign: errore temporaneo del servizio durante "%1" (HTTP %2).', $operation, $status)
            );
        }

        // 4xx: configurazione o dati errati, il retry non aiuta
        throw ProviderException::permanent(
            __('WsSign: richiesta rifiutata durante "%1" (HTTP %2).', $operation, $status)
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
                __('WsSign: risposta non-JSON durante "%1".', $operation),
                $e
            );
        }
        if (!is_array($data)) {
            throw ProviderException::permanent(__('WsSign: struttura risposta inattesa durante "%1".', $operation));
        }

        return $data;
    }
}
