<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Notification;

use MageOS\DigitalSignature\Model\Service\AuditTrailComplianceGate;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Notification\MessageInterface;
use Magento\Framework\Phrase;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Admin dashboard notice (bell icon) shown while Digital Signature is enabled
 * on a store but the audit trail compliance choice has not been made yet:
 * the module blocks document generation for that store until it is.
 */
class AuditTrailComplianceMessage implements MessageInterface
{
    private const XML_PATH_ENABLED = 'digital_signature/general/enabled';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly AuditTrailComplianceGate $gate
    ) {
    }

    public function getIdentity(): string
    {
        return 'mageos_digitalsignature_audit_trail_compliance';
    }

    public function isDisplayed(): bool
    {
        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int)$store->getId();
            if (!$this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId)) {
                continue;
            }
            if (!$this->gate->isConfigured($storeId)) {
                return true;
            }
        }

        return false;
    }

    public function getText(): Phrase
    {
        return __(
            'Digital Signature is enabled but the audit trail compliance choice (Stores > Configuration > Mage-OS > Digital Signature > Audit trail) has not been set for at least one store: document generation is blocked there until it is configured.'
        );
    }

    public function getSeverity(): int
    {
        return self::SEVERITY_CRITICAL;
    }
}
