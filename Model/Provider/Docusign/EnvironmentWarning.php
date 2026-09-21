<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Provider\Docusign;

use MageOS\DigitalSignature\Model\Provider\Docusign;
use MageOS\DigitalSignature\Model\Provider\ProviderConfig;

/**
 * Decides whether the "still in Demo/Sandbox mode" admin banner should be shown for DocuSign.
 */
class EnvironmentWarning
{
    public function __construct(private readonly ProviderConfig $config)
    {
    }

    public function getMessage(?int $storeId = null): ?string
    {
        $environment = $this->config->get(Docusign::CODE, 'environment', $storeId) ?: 'demo';
        if ($environment === 'production') {
            return null;
        }

        return (string)__(
            'Currently running in Demo / Sandbox mode: envelopes are not sent to real signers.'
        );
    }
}
