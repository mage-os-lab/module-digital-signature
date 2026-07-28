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
                (string)__('Connector to integrate digital and electronic signatures from Adobe Acrobat Sign. Supports OAuth2 authentication and asynchronous signature process tracking.'),
                'https://www.adobe.com/sign.html'
            )
        ];
    }
}
