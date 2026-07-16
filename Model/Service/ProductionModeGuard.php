<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Service;

use MageOS\DigitalSignature\Model\Provider\Dummy;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Gate GDPR: il modulo può usare provider di firma reali solo dopo che il
 * merchant ha spuntato la presa d'atto di avere un DPA con il provider e una
 * privacy policy aggiornata. In assenza della presa d'atto, i nuovi
 * documenti vengono forzati sul provider dummy (test), indipendentemente dal
 * provider configurato in "Provider di firma attivo".
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
     * Restituisce il provider da usare per un nuovo documento: quello
     * configurato se la modalità produzione è confermata, altrimenti forza
     * il provider dummy.
     */
    public function resolveProviderCode(string $configuredProviderCode, int $storeId): string
    {
        if ($this->isProductionConfirmed($storeId)) {
            return $configuredProviderCode;
        }

        return Dummy::CODE;
    }
}
