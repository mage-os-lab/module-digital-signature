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
            'Servizio di firma digitale integrato nell\'ecosistema Adobe Document Cloud. Supporta standard '
            . 'eIDAS per firme elettroniche Semplici (SES), Avanzate (AES) e Qualificate (QES). Consente '
            . 'di orchestrare l\'invio di accordi (agreements) e tracciarne l\'esito in tempo reale tramite webhook.'
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
