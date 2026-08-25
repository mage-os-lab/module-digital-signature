<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Api\Data;

/**
 * Data transfer object representing the quota status of a signature provider.
 */
interface QuotaStatusInterface
{
    public const QUOTA_TYPE_PREPAID = 'prepaid_pool';
    public const QUOTA_TYPE_MONTHLY = 'monthly_allowance';
    public const QUOTA_TYPE_ANNUAL = 'annual_allowance';

    public function getProviderCode(): string;

    public function isQuotaEnabled(): bool;

    public function getQuotaType(): string;

    public function getTotalQuota(): int;

    public function getUsedQuota(): int;

    public function getRemainingQuota(): int;

    public function getPercentageUsed(): float;

    public function isWarningThreshold(): bool;

    public function isExhausted(): bool;

    public function getFormattedMessage(): string;
}
