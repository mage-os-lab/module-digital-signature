<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Connector;

class AvailableConnectorsCatalog
{
    /**
     * Get all available or planned connectors.
     *
     * @return AvailableConnector[]
     */
    public function getAll(): array
    {
        return [
            new AvailableConnector(
                'adobe_sign',
                (string)__('Adobe Acrobat Sign'),
                'in_development',
                (string)__('Connettore per integrare le firme digitali ed elettroniche di Adobe Acrobat Sign. Consente l\'autenticazione OAuth2 ed il tracciamento asincrono del processo di firma.'),
                'https://www.adobe.com/sign.html'
            )
        ];
    }
}
