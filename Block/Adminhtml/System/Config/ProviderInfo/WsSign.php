<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Block\Adminhtml\System\Config\ProviderInfo;

class WsSign extends AbstractProviderInfo
{
    public function getLogoUrl(): string
    {
        return $this->getViewFileUrl('MageOS_DigitalSignature::images/providers/wssign.png');
    }

    public function getProviderName(): string
    {
        return (string)__('WsSign');
    }

    public function isRecommended(): bool
    {
        return true;
    }

    public function getRecommendationText(): ?string
    {
        return (string)__(
            'Recommended choice. WsSign is the native connector, developed and maintained alongside this module: no third-party integration to configure, predictable costs, and direct support in Italian. For most use cases it\'s the simplest solution to activate.'
        );
    }

    public function getDescription(): string
    {
        return (string)__(
            'Advanced electronic signature platform (OTP via SMS or email). The connector uploads the PDF generated from the template, starts the signing request to the signer, and receives status updates via an authenticated callback. Requires an active WsSign account (tenant, owner user, password) provided by the WsSign team.'
        );
    }

    public function getLegalLevel(): string
    {
        return (string)__('Advanced Electronic Signature (AES)');
    }

    public function getLinks(): array
    {
        // NOTE: no official public URL is hardcoded here (to be confirmed/
        // filled in with the customer's WsSign agency/account manager before
        // deployment). The API documentation is the one already collected in the repository.
        return [
            ['label' => (string)__('API documentation (in the module repository)'), 'note' => 'docs/wssign-api.md'],
        ];
    }
}
