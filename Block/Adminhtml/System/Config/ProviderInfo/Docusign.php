<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Block\Adminhtml\System\Config\ProviderInfo;

class Docusign extends AbstractProviderInfo
{
    /**
     * @return string
     */
    public function getLogoUrl(): string
    {
        return $this->getViewFileUrl('MageOS_DigitalSignature::images/providers/docusign.svg');
    }

    /**
     * @return string
     */
    public function getProviderName(): string
    {
        return (string)__('DocuSign');
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return (string)__(
            'Global leading electronic signature platform. Supports Simple (SES), Advanced (AES), and Qualified (QES) electronic signatures. The integration allows automatic sending of envelopes with the contractual PDFs generated at order time, tracking their status through to completion.'
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
                'label' => (string)__('DocuSign Developer Center'),
                'url' => 'https://developers.docusign.com/'
            ],
            [
                'label' => (string)__('DocuSign Apps and Keys Panel'),
                'url' => 'https://admindemo.docusign.com/apps-and-keys'
            ]
        ];
    }
}
