<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Provider;

use MageOS\DigitalSignature\Api\Data\DocumentInterface;
use MageOS\DigitalSignature\Api\SignProviderInterface;
use MageOS\DigitalSignature\Exception\ProviderException;
use MageOS\DigitalSignature\Model\Document\Status;
use MageOS\DigitalSignature\Model\Provider\Docusign\Client;
use MageOS\DigitalSignature\Model\Provider\Result\StartResult;
use MageOS\DigitalSignature\Model\Provider\Result\StatusResult;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;

class Docusign implements SignProviderInterface
{
    public const CODE = 'docusign';

    public function __construct(
        private readonly Client $client,
        private readonly ProviderConfig $config,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly Json $json
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return 'DocuSign';
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->config->isSetFlag(self::CODE, 'enabled', $storeId)
            && $this->config->get(self::CODE, 'integration_key', $storeId)
            && $this->config->get(self::CODE, 'user_id', $storeId)
            && $this->config->getSecret(self::CODE, 'private_key', $storeId);
    }

    public function start(DocumentInterface $document, string $pdfContent, string $callbackToken): StartResult
    {
        $storeId = $document->getStoreId();
        $email = $this->requireValidEmail($document);
        $name = $this->getSignerName($document);

        $baseUrl = $this->getStoreBaseUrl($storeId);
        $callbackUrl = $baseUrl . 'digitalsignature/callback/index/id/'
            . $document->getDocumentId() . '/token/' . $callbackToken;

        $fileName = sprintf('documento-ordine-%d.pdf', $document->getOrderId());
        $expirationDays = max(1, (int)($this->config->get(self::CODE, 'expiration_days', $storeId) ?? 7));

        $envelopeData = [
            'emailSubject' => (string)__('Sign contract for order #%1', $document->getOrderId()),
            'emailBlurb' => (string)($this->config->get(self::CODE, 'notes', $storeId)
                ?? __('We kindly ask you to digitally sign the document related to your order.')),
            'documents' => [
                [
                    'documentId' => '1',
                    'name' => $fileName,
                    'fileExtension' => 'pdf',
                    'documentBase64' => base64_encode($pdfContent)
                ]
            ],
            'recipients' => [
                'signers' => [
                    [
                        'email' => $email,
                        'name' => $name,
                        'recipientId' => '1',
                        'routingOrder' => '1',
                        'tabs' => [
                            'signHereTabs' => [
                                [
                                    'anchorString' => '{WSIGN#',
                                    'anchorUnits' => 'pixels',
                                    'anchorXOffset' => '0',
                                    'anchorYOffset' => '0',
                                    'recipientId' => '1',
                                    'documentId' => '1'
                                ]
                            ]
                        ]
                    ]
                ]
            ],
            'notification' => [
                'expirations' => [
                    'expireEnabled' => 'true',
                    'expireAfter' => (string)$expirationDays,
                    'expireWarn' => '2'
                ]
            ],
            'eventNotification' => [
                'url' => $callbackUrl,
                'loggingEnabled' => 'true',
                'requireAcknowledgment' => 'true',
                'useSoapInterface' => 'false',
                'includeDocuments' => 'false',
                'includeEnvelopeVoidReason' => 'true',
                'includeTimeZone' => 'true',
                'envelopeEvents' => [
                    [ 'envelopeEventStatusCode' => 'sent' ],
                    [ 'envelopeEventStatusCode' => 'delivered' ],
                    [ 'envelopeEventStatusCode' => 'completed' ],
                    [ 'envelopeEventStatusCode' => 'declined' ],
                    [ 'envelopeEventStatusCode' => 'voided' ]
                ]
            ],
            'status' => 'sent'
        ];

        $response = $this->client->createEnvelope($storeId, $envelopeData);
        $envelopeId = $response['envelopeId'] ?? null;

        if (!$envelopeId) {
            throw ProviderException::permanent(__('DocuSign: envelope creation failed, no ID received.'));
        }

        return new StartResult($envelopeId, $this->json->serialize($response));
    }

    public function fetchStatus(DocumentInterface $document): StatusResult
    {
        $storeId = $document->getStoreId();
        $envelopeId = $this->requireProcessId($document);

        $response = $this->client->getEnvelopeStatus($storeId, $envelopeId);
        $rawStatus = $response['status'] ?? null;

        if (!$rawStatus) {
            throw ProviderException::permanent(__('DocuSign: status response is missing the status field.'));
        }

        return new StatusResult($rawStatus, $this->json->serialize($response));
    }

    public function downloadSignedPdf(DocumentInterface $document): string
    {
        $storeId = $document->getStoreId();
        $envelopeId = $this->requireProcessId($document);

        return $this->client->downloadDocument($storeId, $envelopeId);
    }

    public function cancel(DocumentInterface $document): void
    {
        $envelopeId = $document->getProviderProcessId();
        if ($envelopeId === null) {
            return;
        }
        $storeId = $document->getStoreId();
        $this->client->voidEnvelope($storeId, $envelopeId, 'Cancellation/regeneration requested from Mage-OS.');
    }

    public function mapStatus(string $providerStatus): ?string
    {
        $configured = $this->config->mapConfiguredStatus(self::CODE, $providerStatus);
        if ($configured !== null) {
            return $configured;
        }

        return match ($providerStatus) {
            'sent' => Status::SENT,
            'delivered' => Status::SENT,
            'completed' => Status::SIGNED,
            'declined' => Status::DECLINED,
            'voided' => Status::EXPIRED,
            default => null,
        };
    }

    private function requireProcessId(DocumentInterface $document): string
    {
        $processId = $document->getProviderProcessId();
        if ($processId === null || $processId === '') {
            throw ProviderException::permanent(__('DocuSign: the document has no started process (envelope).'));
        }

        return $processId;
    }

    private function requireValidEmail(DocumentInterface $document): string
    {
        $email = (string)($document->getSignerEmail() ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\x00-\x1F\x7F]/', $email)) {
            throw ProviderException::permanent(__('DocuSign: signer email is missing or invalid.'));
        }

        return $email;
    }

    private function getSignerName(DocumentInterface $document): string
    {
        try {
            $order = $this->orderRepository->get($document->getOrderId());
            $name = trim($order->getCustomerFirstname() . ' ' . $order->getCustomerLastname());
            if ($name === '') {
                $name = $order->getCustomerName();
            }
            if (empty($name) && $order->getBillingAddress()) {
                $name = $order->getBillingAddress()->getName();
            }
            return $name ?: 'Customer';
        } catch (\Exception) {
            return 'Customer';
        }
    }

    private function getStoreBaseUrl(int $storeId): string
    {
        try {
            return $this->storeManager->getStore($storeId)->getBaseUrl();
        } catch (\Exception $e) {
            throw ProviderException::permanent(__('DocuSign: invalid document store.'), $e);
        }
    }
}
