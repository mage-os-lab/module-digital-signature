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
        return (string)__('Dummy (solo test)');
    }

    public function getDescription(): string
    {
        return (string)__(
            'Provider fittizio incluso nel modulo: non contatta nessun servizio esterno. Simula una firma '
            . 'riuscita dopo il ritardo configurato sotto, restituendo il PDF originale come "firmato". '
            . 'Utile per collaudare l\'intero flusso (template, trigger, notifiche, area cliente) senza '
            . 'credenziali di un provider reale. Da NON abilitare in produzione.'
        );
    }

    public function getLegalLevel(): string
    {
        return (string)__('Nessuna validità legale — solo test/sviluppo');
    }

    public function getLinks(): array
    {
        return [];
    }
}
