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
            'Piattaforma di firma elettronica leader globale. Supporta firme elettroniche Semplici (SES), '
            . 'Avanzate (AES) e Qualificate (QES). L\'integrazione consente l\'invio automatico delle buste (envelopes) '
            . 'con i PDF contrattuali generati al momento dell\'ordine, tracciandone lo stato fino al completamento.'
        );
    }

    /**
     * @return string
     */
    public function getLegalLevel(): string
    {
        return (string)__('Firma Elettronica Semplice (SES), Avanzata (AES), Qualificata (QES)');
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
