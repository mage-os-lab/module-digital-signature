<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Block\Adminhtml\System\Config\ProviderInfo;

class WsSign extends AbstractProviderInfo
{
    public function getLogoUrl(): string
    {
        return $this->getViewFileUrl('MageOS_DigitalSignature::images/providers/wssign.svg');
    }

    public function getProviderName(): string
    {
        return (string)__('WsSign');
    }

    public function getDescription(): string
    {
        return (string)__(
            'Piattaforma di firma elettronica avanzata (OTP via SMS o email). Il connettore carica il '
            . 'PDF generato dal template, avvia la richiesta di firma verso il firmatario e riceve gli '
            . 'aggiornamenti di stato tramite callback autenticata. Richiede un account WsSign attivo '
            . '(tenant, utente owner, password) fornito dal team WsSign.'
        );
    }

    public function getLegalLevel(): string
    {
        return (string)__('Firma Elettronica Avanzata (AES)');
    }

    public function getLinks(): array
    {
        // NOTA: nessun URL pubblico ufficiale è codificato qui (da confermare/
        // valorizzare con l'agenzia/account manager WsSign del cliente prima del
        // deploy). La documentazione API è quella già raccolta nel repository.
        return [
            ['label' => (string)__('Documentazione API (nel repository del modulo)'), 'note' => 'docs/wssign-api.md'],
        ];
    }
}
