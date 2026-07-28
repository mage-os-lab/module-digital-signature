<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Provider;

use MageOS\DigitalSignature\Api\Data\DocumentInterface;
use MageOS\DigitalSignature\Api\SignProviderInterface;
use MageOS\DigitalSignature\Exception\ProviderException;
use MageOS\DigitalSignature\Model\Document\Status;
use MageOS\DigitalSignature\Model\Provider\AdobeSign\Client;
use MageOS\DigitalSignature\Model\Provider\Result\StartResult;
use MageOS\DigitalSignature\Model\Provider\Result\StatusResult;
use Magento\Framework\Serialize\Serializer\Json;

class AdobeSign implements SignProviderInterface
{
    public const CODE = 'adobesign';

    public function __construct(
        private readonly Client $client,
        private readonly ProviderConfig $config,
        private readonly Json $json
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return 'Adobe Acrobat Sign';
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->config->isSetFlag(self::CODE, 'enabled', $storeId)
            && $this->config->get(self::CODE, 'client_id', $storeId)
            && $this->config->getSecret(self::CODE, 'client_secret', $storeId)
            && $this->config->getSecret(self::CODE, 'refresh_token', $storeId)
            && $this->config->get(self::CODE, 'api_access_point', $storeId);
    }

    public function start(DocumentInterface $document, string $pdfContent, string $callbackToken): StartResult
    {
        $storeId = $document->getStoreId();
        $email = $this->requireValidEmail($document);

        $fileName = sprintf('documento-ordine-%d.pdf', $document->getOrderId());
        $expirationDays = max(1, (int)($this->config->get(self::CODE, 'expiration_days', $storeId) ?? 7));

        $agreementData = [
            'name' => (string)__('Sign contract for order #%1', $document->getOrderId()),
            'message' => (string)($this->config->get(self::CODE, 'notes', $storeId)
                ?? __('We kindly ask you to digitally sign the document related to your order.')),
            'participantSetsInfo' => [
                [
                    'memberInfos' => [
                        ['email' => $email]
                    ],
                    'order' => 1,
                    'role' => 'SIGNER'
                ]
            ],
            'signatureType' => 'ESIGN',
            'state' => 'IN_PROCESS',
            'daysUntilSigningDeadline' => $expirationDays
        ];

        $response = $this->client->createAgreement($storeId, $agreementData, $fileName, $pdfContent);
        $agreementId = $response['id'] ?? null;

        if (!$agreementId) {
            throw ProviderException::permanent(__('Adobe Acrobat Sign: agreement creation failed, no ID received.'));
        }

        return new StartResult($agreementId, $this->json->serialize($response));
    }

    public function fetchStatus(DocumentInterface $document): StatusResult
    {
        $storeId = $document->getStoreId();
        $agreementId = $this->requireProcessId($document);

        $response = $this->client->getAgreementStatus($storeId, $agreementId);
        $rawStatus = $response['status'] ?? null;

        if (!$rawStatus) {
            throw ProviderException::permanent(__('Adobe Acrobat Sign: status response is missing the status field.'));
        }

        return new StatusResult($rawStatus, $this->json->serialize($response));
    }

    public function downloadSignedPdf(DocumentInterface $document): string
    {
        $storeId = $document->getStoreId();
        $agreementId = $this->requireProcessId($document);

        return $this->client->downloadCombinedDocument($storeId, $agreementId);
    }

    public function cancel(DocumentInterface $document): void
    {
        $agreementId = $document->getProviderProcessId();
        if ($agreementId === null) {
            return;
        }
        $storeId = $document->getStoreId();
        $this->client->cancelAgreement($storeId, $agreementId, 'Richiesta annullamento/rigenerazione da Mage-OS.');
    }

    public function mapStatus(string $providerStatus): ?string
    {
        // Mapping of Adobe Acrobat Sign agreement statuses to internal statuses
        return match ($providerStatus) {
            'OUT_FOR_SIGNATURE' => Status::SENT,
            'OUT_FOR_APPROVAL' => Status::SENT,
            'SIGNED' => Status::SIGNED,
            'APPROVED' => Status::SIGNED,
            'REJECTED' => Status::DECLINED,
            'CANCELLED' => Status::DECLINED,
            'EXPIRED' => Status::EXPIRED,
            default => null,
        };
    }

    private function requireProcessId(DocumentInterface $document): string
    {
        $processId = $document->getProviderProcessId();
        if ($processId === null || $processId === '') {
            throw ProviderException::permanent(
                __('Adobe Acrobat Sign: the document has no started process (agreement).')
            );
        }

        return $processId;
    }

    private function requireValidEmail(DocumentInterface $document): string
    {
        $email = (string)($document->getSignerEmail() ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\x00-\x1F\x7F]/', $email)) {
            throw ProviderException::permanent(__('Adobe Acrobat Sign: signer email is missing or invalid.'));
        }

        return $email;
    }
}
