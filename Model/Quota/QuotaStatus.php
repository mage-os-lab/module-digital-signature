<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Quota;

use MageOS\DigitalSignature\Api\Data\QuotaStatusInterface;

class QuotaStatus implements QuotaStatusInterface
{
    public function __construct(
        private readonly string $providerCode,
        private readonly bool $quotaEnabled,
        private readonly string $quotaType,
        private readonly int $totalQuota,
        private readonly int $usedQuota,
        private readonly int $remainingQuota,
        private readonly float $percentageUsed,
        private readonly bool $warningThreshold,
        private readonly bool $exhausted,
        private readonly string $formattedMessage
    ) {
    }

    public function getProviderCode(): string
    {
        return $this->providerCode;
    }

    public function isQuotaEnabled(): bool
    {
        return $this->quotaEnabled;
    }

    public function getQuotaType(): string
    {
        return $this->quotaType;
    }

    public function getTotalQuota(): int
    {
        return $this->totalQuota;
    }

    public function getUsedQuota(): int
    {
        return $this->usedQuota;
    }

    public function getRemainingQuota(): int
    {
        return $this->remainingQuota;
    }

    public function getPercentageUsed(): float
    {
        return $this->percentageUsed;
    }

    public function isWarningThreshold(): bool
    {
        return $this->warningThreshold;
    }

    public function isExhausted(): bool
    {
        return $this->exhausted;
    }

    public function getFormattedMessage(): string
    {
        return $this->formattedMessage;
    }
}
