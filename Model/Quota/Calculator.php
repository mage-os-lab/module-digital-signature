<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Quota;

use MageOS\DigitalSignature\Api\Data\DocumentInterface;
use MageOS\DigitalSignature\Api\Data\QuotaStatusInterface;
use MageOS\DigitalSignature\Model\Provider\ProviderConfig;
use MageOS\DigitalSignature\Model\ResourceModel\Document\CollectionFactory;
use MageOS\DigitalSignature\Model\Document\Status;

class Calculator
{
    private const EXCLUDED_STATUSES = [
        Status::ERROR
    ];

    public function __construct(
        private readonly ProviderConfig $providerConfig,
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * Calculates quota status for a specific provider.
     */
    public function calculate(string $providerCode, ?int $storeId = null): QuotaStatusInterface
    {
        $enabled = (bool)$this->providerConfig->get($providerCode, 'quota_enabled', $storeId);
        $totalQuota = (int)($this->providerConfig->get($providerCode, 'total_quota', $storeId) ?: 0);
        $quotaType = (string)($this->providerConfig->get($providerCode, 'quota_type', $storeId) ?: QuotaStatusInterface::QUOTA_TYPE_PREPAID);
        $thresholdPercent = (int)($this->providerConfig->get($providerCode, 'alert_threshold_percent', $storeId) ?: 15);
        $thresholdCount = (int)($this->providerConfig->get($providerCode, 'alert_threshold_count', $storeId) ?: 20);

        if (!$enabled || $totalQuota <= 0) {
            return new QuotaStatus(
                $providerCode,
                false,
                $quotaType,
                $totalQuota,
                0,
                $totalQuota,
                0.0,
                false,
                false,
                __('Quota tracking disabled.')->render()
            );
        }

        $startDate = $this->resolveStartDate($providerCode, $quotaType, $storeId);
        $usedQuota = $this->countUsedQuota($providerCode, $startDate, $storeId);
        $remainingQuota = max(0, $totalQuota - $usedQuota);
        $percentageUsed = min(100.0, round(($usedQuota / $totalQuota) * 100, 1));

        $isExhausted = $usedQuota >= $totalQuota;
        $isWarningByPercent = ((100 - $percentageUsed) <= $thresholdPercent);
        $isWarningByCount = ($remainingQuota <= $thresholdCount);
        $isWarning = $isWarningByPercent || $isWarningByCount || $isExhausted;

        $message = sprintf(
            '%d / %d used (%d remaining - %.1f%%)',
            $usedQuota,
            $totalQuota,
            $remainingQuota,
            $percentageUsed
        );

        return new QuotaStatus(
            $providerCode,
            true,
            $quotaType,
            $totalQuota,
            $usedQuota,
            $remainingQuota,
            $percentageUsed,
            $isWarning,
            $isExhausted,
            $message
        );
    }

    /**
     * Resolves the start date filter depending on quota type.
     */
    private function resolveStartDate(string $providerCode, string $quotaType, ?int $storeId): ?string
    {
        $configuredDate = $this->providerConfig->get($providerCode, 'quota_start_date', $storeId);
        if ($quotaType === QuotaStatusInterface::QUOTA_TYPE_PREPAID) {
            return $configuredDate ? date('Y-m-d 00:00:00', strtotime((string)$configuredDate)) : null;
        }

        if ($quotaType === QuotaStatusInterface::QUOTA_TYPE_MONTHLY) {
            $resetDay = (int)($this->providerConfig->get($providerCode, 'reset_day', $storeId) ?: 1);
            $currentDay = (int)date('j');
            if ($currentDay >= $resetDay) {
                return date(sprintf('Y-m-%02d 00:00:00', $resetDay));
            }
            return date(sprintf('Y-m-%02d 00:00:00', $resetDay), strtotime('-1 month'));
        }

        if ($quotaType === QuotaStatusInterface::QUOTA_TYPE_ANNUAL) {
            return date('Y-01-01 00:00:00');
        }

        return null;
    }

    /**
     * Queries document collection to count consumption.
     */
    private function countUsedQuota(string $providerCode, ?string $startDate, ?int $storeId): int
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(DocumentInterface::PROVIDER_CODE, $providerCode);

        if ($storeId !== null) {
            $collection->addFieldToFilter(DocumentInterface::STORE_ID, $storeId);
        }

        if ($startDate !== null) {
            $collection->addFieldToFilter(DocumentInterface::CREATED_AT, ['gteq' => $startDate]);
        }

        $collection->addFieldToFilter(DocumentInterface::STATUS, ['nin' => self::EXCLUDED_STATUSES]);

        return $collection->getSize();
    }
}
