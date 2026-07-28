<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Webhook;

/**
 * Exponential backoff for webhook delivery retries: 1', 5', 30', 2h.
 * Beyond the number of defined thresholds it stays fixed on the last one.
 */
class BackoffCalculator
{
    private const SCHEDULE_MINUTES = [1, 5, 30, 120];

    public function nextAttemptDelayMinutes(int $attempts): int
    {
        $index = max(0, min($attempts - 1, count(self::SCHEDULE_MINUTES) - 1));

        return self::SCHEDULE_MINUTES[$index];
    }
}
