<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Block\Adminhtml\System\Config\ProviderInfo;

class Dummy extends AbstractProviderInfo
{
    public function getLogoUrl(): string
    {
        return $this->getViewFileUrl('MageOS_DigitalSignature::images/providers/dummy.svg');
    }

    public function getProviderName(): string
    {
        return (string)__('Dummy (test only)');
    }

    public function getDescription(): string
    {
        return (string)__(
            'Dummy provider included with the module: it does not contact any external service. It simulates a successful signature after the delay configured below, returning the original PDF as "signed". Useful for testing the whole flow (templates, triggers, notifications, customer area) without a real provider\'s credentials. Do NOT enable in production.'
        );
    }

    public function getLegalLevel(): string
    {
        return (string)__('No legal validity — test/development only');
    }

    public function getLinks(): array
    {
        return [];
    }
}
