<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Document\Stats;

class PeriodSource
{
    public const PERIOD_30_DAYS = '30d';
    public const PERIOD_90_DAYS = '90d';
    public const PERIOD_365_DAYS = '365d';
    public const PERIOD_ALL = 'all';

    public function getOptions(): array
    {
        return [
            self::PERIOD_30_DAYS => __('Ultimi 30 giorni'),
            self::PERIOD_90_DAYS => __('Ultimi 90 giorni'),
            self::PERIOD_365_DAYS => __('Ultimo anno'),
            self::PERIOD_ALL => __('Sempre')
        ];
    }

    /**
     * @return array{from: ?\DateTime, to: \DateTime}
     */
    public function getDateRange(string $period, ?\DateTimeInterface $now = null): array
    {
        $to = $now instanceof \DateTime ? clone $now : new \DateTime('now', new \DateTimeZone('UTC'));
        $from = null;

        switch ($period) {
            case self::PERIOD_30_DAYS:
                $from = (clone $to)->sub(new \DateInterval('P30D'));
                break;
            case self::PERIOD_90_DAYS:
                $from = (clone $to)->sub(new \DateInterval('P90D'));
                break;
            case self::PERIOD_365_DAYS:
                $from = (clone $to)->sub(new \DateInterval('P365D'));
                break;
            case self::PERIOD_ALL:
            default:
                $from = null;
                break;
        }

        if ($from !== null) {
            $from->setTime(0, 0, 0);
        }

        return ['from' => $from, 'to' => $to];
    }
}
