<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Block\Adminhtml\System\Config\ProviderInfo;

class AdobeSign extends AbstractProviderInfo
{
    /**
     * @return string
     */
    public function getLogoUrl(): string
    {
        return $this->getViewFileUrl('MageOS_DigitalSignature::images/providers/adobesign.svg');
    }

    /**
     * @return string
     */
    public function getProviderName(): string
    {
        return (string)__('Adobe Acrobat Sign');
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return (string)__(
            'Digital signature service integrated into the Adobe Document Cloud ecosystem. Supports the eIDAS standard for Simple (SES), Advanced (AES), and Qualified (QES) electronic signatures. Allows you to orchestrate the sending of agreements and track their outcome through to completion.'
        );
    }

    /**
     * @return string
     */
    public function getLegalLevel(): string
    {
        return (string)__('Simple (SES), Advanced (AES), Qualified (QES) Electronic Signature');
    }

    /**
     * @return array<int, array{label: string, url: string}>
     */
    public function getLinks(): array
    {
        return [
            [
                'label' => (string)__('Adobe Acrobat Sign Developer Guide'),
                'url' => 'https://secure.adobesign.com/public/static/developer'
            ],
            [
                'label' => (string)__('Adobe Developer Console'),
                'url' => 'https://developer.adobe.com/console/'
            ]
        ];
    }
}
