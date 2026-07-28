<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Service;

use MageOS\DigitalSignature\Model\Provider\Dummy;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * GDPR gate: the module can use real signature providers only after the
 * merchant has checked the acknowledgment of having a DPA with the provider and
 * an up-to-date privacy policy. In the absence of the acknowledgment, new
 * documents are forced onto the dummy (test) provider, regardless of the
 * provider configured in "Active signature provider".
 */
class ProductionModeGuard
{
    private const XML_PATH_PRODUCTION_ACK = 'digital_signature/general/production_ack';

    public function __construct(private readonly ScopeConfigInterface $scopeConfig)
    {
    }

    public function isProductionConfirmed(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_PRODUCTION_ACK,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Returns the provider to use for a new document: the configured one if
     * production mode is confirmed, otherwise forces the dummy provider.
     */
    public function resolveProviderCode(string $configuredProviderCode, int $storeId): string
    {
        if ($this->isProductionConfirmed($storeId)) {
            return $configuredProviderCode;
        }

        return Dummy::CODE;
    }
}
