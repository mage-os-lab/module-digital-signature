<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Document\Stats;

class Summary
{
    public function __construct(
        private readonly int $totalGenerated,
        private readonly int $signedCount,
        private readonly float $signedPercentage,
        private readonly int $expiredDeclinedCount,
        private readonly float $expiredDeclinedPercentage,
        private readonly int $pendingCount,
        private readonly float $avgSigningTimeDays
    ) {
    }

    public function getTotalGenerated(): int
    {
        return $this->totalGenerated;
    }

    public function getSignedCount(): int
    {
        return $this->signedCount;
    }

    public function getSignedPercentage(): float
    {
        return $this->signedPercentage;
    }

    public function getExpiredDeclinedCount(): int
    {
        return $this->expiredDeclinedCount;
    }

    public function getExpiredDeclinedPercentage(): float
    {
        return $this->expiredDeclinedPercentage;
    }

    public function getPendingCount(): int
    {
        return $this->pendingCount;
    }

    public function getAvgSigningTimeDays(): float
    {
        return $this->avgSigningTimeDays;
    }
}
