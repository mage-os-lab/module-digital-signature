<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Blocking gate: no signature document may be generated until the merchant
 * has made an explicit choice about audit trail data collection (eIDAS
 * compliance). An unset choice blocks; an explicit opt-out is a valid,
 * unblocking configuration on its own.
 */
class AuditTrailComplianceGate
{
    private const XML_PATH_DATA_COLLECTION = 'digital_signature/audit_trail/data_collection';
    private const XML_PATH_RETENTION_DAYS = 'digital_signature/audit_trail/retention_days';
    private const XML_PATH_POLICY_ACK = 'digital_signature/audit_trail/policy_ack';

    public function __construct(private readonly ScopeConfigInterface $scopeConfig)
    {
    }

    public function isConfigured(int $storeId): bool
    {
        $choice = (string)$this->scopeConfig->getValue(
            self::XML_PATH_DATA_COLLECTION,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if ($choice === '0') {
            return true;
        }

        if ($choice !== '1') {
            return false;
        }

        $ack = $this->scopeConfig->isSetFlag(
            self::XML_PATH_POLICY_ACK,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $retentionDays = (int)$this->scopeConfig->getValue(
            self::XML_PATH_RETENTION_DAYS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $ack && $retentionDays > 0;
    }

    /**
     * True only for an explicit opt-in that is also fully configured (policy
     * acknowledged, retention period set): audit trail data is never collected
     * on the strength of the Yes alone.
     */
    public function isDataCollectionEnabled(int $storeId): bool
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_DATA_COLLECTION,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) === '1' && $this->isConfigured($storeId);
    }
}
