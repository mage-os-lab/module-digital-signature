<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Provider;

use MageOS\DigitalSignature\Api\Data\DocumentInterface;
use MageOS\DigitalSignature\Api\SignProviderInterface;
use MageOS\DigitalSignature\Exception\ProviderException;
use MageOS\DigitalSignature\Model\Document\Status;
use MageOS\DigitalSignature\Model\Provider\Result\StartResult;
use MageOS\DigitalSignature\Model\Provider\Result\StatusResult;
use MageOS\DigitalSignature\Model\Provider\WsSign\Client;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Url\Validator as UrlValidator;
use Magento\Store\Model\StoreManagerInterface;

class WsSign implements SignProviderInterface
{
    public const CODE = 'wssign';

    /** Synthetic status used when the provider responds 404 on the process */
    public const RAW_STATUS_NOT_FOUND = 'NOT_FOUND';

    public function __construct(
        private readonly Client $client,
        private readonly ProviderConfig $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly Json $json,
        private readonly UrlValidator $urlValidator
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return 'WsSign';
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->config->isSetFlag(self::CODE, 'enabled', $storeId)
            && $this->config->get(self::CODE, 'platform_url', $storeId)
            && $this->config->get(self::CODE, 'username', $storeId);
    }

    public function start(DocumentInterface $document, string $pdfContent, string $callbackToken): StartResult
    {
        $storeId = $document->getStoreId();
        $email = $this->requireValidEmail($document);
        $phone = $this->requireValidPhone($document, $storeId);

        $platformUrl = $this->getPlatformUrl($storeId);
        $owner = (string)$this->config->get(self::CODE, 'username', $storeId);
        $token = $this->fetchToken($storeId);

        $fileName = sprintf('documento-ordine-%d-%d.pdf', $document->getOrderId(), $document->getOrderItemId());
        $guid = $this->client->uploadDocument($platformUrl, $token, $owner, $fileName, $pdfContent);

        $expirationDays = max(1, (int)($this->config->get(self::CODE, 'expiration_days', $storeId) ?? 7));
        $baseUrl = $this->getStoreBaseUrl($storeId);
        $sharePayload = [
            'receivers' => [
                [
                    'email' => $email,
                    'phonePrefix' => $phone['prefix'],
                    'phone' => $phone['number'],
                    'minSignatures' => 1,
                    'channel' => 'EMAIL',
                    'locale' => 'it',
                ],
            ],
            'notes' => (string)($this->config->get(self::CODE, 'notes', $storeId)
                ?? __('We kindly ask you to digitally sign the document related to your order.')),
            'expirationDate' => date('Y-m-d', strtotime('+' . $expirationDays . ' days')),
            'signatureType' => (string)($this->config->get(self::CODE, 'signature_type', $storeId) ?? 'OTP_SMS'),
            'notifyOwner' => false,
            'locale' => 'it',
            'callBackUrl' => $baseUrl . 'digitalsignature/callback/index/id/'
                . $document->getDocumentId() . '/token/' . $callbackToken,
            'redirectUrl' => $baseUrl . 'digitalsignature/callback/thankyou',
        ];
        $notifierEmail = $this->config->get(self::CODE, 'notifier_email', $storeId);
        if ($notifierEmail) {
            $sharePayload['notifiers'] = [['email' => $notifierEmail, 'locale' => 'it']];
        }

        $shareResponse = $this->client->shareDocument($platformUrl, $token, $guid, $sharePayload);

        return new StartResult($guid, $this->json->serialize($shareResponse));
    }

    public function fetchStatus(DocumentInterface $document): StatusResult
    {
        $storeId = $document->getStoreId();
        $guid = $this->requireProcessId($document);
        $token = $this->fetchToken($storeId);

        $data = $this->client->getDocument($this->getPlatformUrl($storeId), $token, $guid);
        if ($data === null) {
            // 404: expired or cancelled on the provider side
            return new StatusResult(self::RAW_STATUS_NOT_FOUND);
        }
        $rawStatus = (string)($data['data']['status'] ?? '');
        if ($rawStatus === '') {
            throw ProviderException::permanent(__('WsSign: status missing from the provider\'s response.'));
        }

        return new StatusResult($rawStatus, $this->json->serialize($data));
    }

    public function downloadSignedPdf(DocumentInterface $document): string
    {
        $storeId = $document->getStoreId();
        $guid = $this->requireProcessId($document);
        $token = $this->fetchToken($storeId);

        return $this->client->downloadDocument($this->getPlatformUrl($storeId), $token, $guid);
    }

    public function cancel(DocumentInterface $document): void
    {
        $processId = $document->getProviderProcessId();
        if ($processId === null) {
            return;
        }
        $storeId = $document->getStoreId();
        $token = $this->fetchToken($storeId);
        $this->client->deleteDocument($this->getPlatformUrl($storeId), $token, $processId);
    }

    public function mapStatus(string $providerStatus): ?string
    {
        $configured = $this->config->mapConfiguredStatus(self::CODE, $providerStatus);
        if ($configured !== null) {
            return $configured;
        }
        // Minimal fallback: 404 on the process = expired/cancelled on the provider side
        if ($providerStatus === self::RAW_STATUS_NOT_FOUND) {
            return Status::EXPIRED;
        }

        return null;
    }

    private function fetchToken(?int $storeId): string
    {
        $username = (string)$this->config->get(self::CODE, 'username', $storeId);
        $password = (string)($this->config->getSecret(self::CODE, 'password', $storeId) ?? '');
        $tenant = (string)($this->config->get(self::CODE, 'tenant', $storeId) ?? '');
        if ($username === '' || $password === '' || $tenant === '') {
            throw ProviderException::permanent(__('WsSign: credentials or tenant not configured.'));
        }

        return $this->client->fetchToken($this->getPlatformUrl($storeId), $tenant, $username, $password);
    }

    private function getPlatformUrl(?int $storeId): string
    {
        $url = (string)($this->config->get(self::CODE, 'platform_url', $storeId) ?? '');
        if (!$this->urlValidator->isValid($url) || !str_starts_with($url, 'https://')) {
            throw ProviderException::permanent(__('WsSign: platform URL not configured or not HTTPS.'));
        }

        return $url;
    }

    private function getStoreBaseUrl(?int $storeId): string
    {
        try {
            return $this->storeManager->getStore($storeId)->getBaseUrl();
        } catch (\Exception $e) {
            throw ProviderException::permanent(__('WsSign: invalid document store.'), $e);
        }
    }

    private function requireProcessId(DocumentInterface $document): string
    {
        $processId = $document->getProviderProcessId();
        if ($processId === null) {
            throw ProviderException::permanent(__('WsSign: the document has no started process.'));
        }

        return $processId;
    }

    private function requireValidEmail(DocumentInterface $document): string
    {
        $email = (string)($document->getSignerEmail() ?? '');
        // Input that flows through the PDF and the provider API: strict validation
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\x00-\x1F\x7F]/', $email)) {
            throw ProviderException::permanent(__('Signer email missing or invalid.'));
        }

        return $email;
    }

    /**
     * @return array{prefix: string, number: string}
     */
    private function requireValidPhone(DocumentInterface $document, ?int $storeId): array
    {
        $raw = trim((string)($document->getSignerPhone() ?? ''));
        if ($raw === '') {
            throw ProviderException::permanent(
                __('Signer\'s phone number missing: required by WsSign for OTP via SMS.')
            );
        }
        $defaultPrefix = (string)($this->config->get(self::CODE, 'default_phone_prefix', $storeId) ?? '+39');
        $prefix = $defaultPrefix;
        if (str_starts_with($raw, '+')) {
            // Separates international prefix (1-3 digits) from the rest
            if (preg_match('/^\+(\d{1,3})(.+)$/', $raw, $matches)) {
                $prefix = '+' . $matches[1];
                $raw = $matches[2];
            }
        }
        $number = preg_replace('/\D/', '', $raw) ?? '';
        if (strlen($number) < 6) {
            throw ProviderException::permanent(__('Signer\'s phone number is invalid.'));
        }

        return ['prefix' => $prefix, 'number' => $number];
    }
}
