<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Provider;

use MageOS\DigitalSignature\Api\Data\DocumentInterface;
use MageOS\DigitalSignature\Api\SignProviderInterface;
use MageOS\DigitalSignature\Exception\ProviderException;
use MageOS\DigitalSignature\Model\Document\Status;
use MageOS\DigitalSignature\Model\Provider\Result\StartResult;
use MageOS\DigitalSignature\Model\Provider\Result\StatusResult;

class AdobeSign implements SignProviderInterface
{
    public const CODE = 'adobesign';

    public function __construct(
        private readonly ProviderConfig $config
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
        return $this->config->isSetFlag(self::CODE, 'enabled', $storeId);
    }

    public function start(DocumentInterface $document, string $pdfContent, string $callbackToken): StartResult
    {
        // TODO: Implementare l'avvio reale tramite API REST di Adobe Acrobat Sign (OAuth Flow)
        throw new ProviderException(
            __('Integrazione Adobe Acrobat Sign in fase di sviluppo. Contattare l\'amministratore.')
        );
    }

    public function fetchStatus(DocumentInterface $document): StatusResult
    {
        // TODO: Implementare l'interrogazione reale tramite API REST di Adobe Acrobat Sign
        throw new ProviderException(
            __('Integrazione Adobe Acrobat Sign in fase di sviluppo. Contattare l\'amministratore.')
        );
    }

    public function downloadSignedPdf(DocumentInterface $document): string
    {
        // TODO: Implementare il download reale del documento firmato tramite API REST di Adobe Acrobat Sign
        throw new ProviderException(
            __('Integrazione Adobe Acrobat Sign in fase di sviluppo. Contattare l\'amministratore.')
        );
    }

    public function cancel(DocumentInterface $document): void
    {
        // TODO: Implementare l'annullamento reale dell'accordo (agreement) tramite API REST di Adobe Acrobat Sign
    }

    public function mapStatus(string $providerStatus): ?string
    {
        // Mappatura degli stati di un accordo (agreement) Adobe Acrobat Sign su stati interni
        return match ($providerStatus) {
            'OUT_FOR_SIGNATURE' => Status::STATUS_IN_PROGRESS,
            'OUT_FOR_APPROVAL' => Status::STATUS_IN_PROGRESS,
            'SIGNED' => Status::STATUS_SIGNED,
            'APPROVED' => Status::STATUS_SIGNED,
            'REJECTED' => Status::STATUS_REJECTED,
            'CANCELLED' => Status::STATUS_REJECTED,
            'EXPIRED' => Status::STATUS_EXPIRED,
            default => null,
        };
    }
}
