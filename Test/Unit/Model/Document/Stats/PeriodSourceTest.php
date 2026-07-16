<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Document\Stats;

use MageOS\DigitalSignature\Model\Document\Stats\PeriodSource;
use PHPUnit\Framework\TestCase;

class PeriodSourceTest extends TestCase
{
    private PeriodSource $periodSource;

    protected function setUp(): void
    {
        $this->periodSource = new PeriodSource();
    }

    public function testGetOptionsReturnsExpectedKeys(): void
    {
        $options = $this->periodSource->getOptions();
        self::assertArrayHasKey('30d', $options);
        self::assertArrayHasKey('90d', $options);
        self::assertArrayHasKey('365d', $options);
        self::assertArrayHasKey('all', $options);
    }

    public function testGetDateRange30Days(): void
    {
        $now = new \DateTime('2026-07-14 15:30:00', new \DateTimeZone('UTC'));
        $range = $this->periodSource->getDateRange('30d', $now);

        self::assertNotNull($range['from']);
        self::assertSame('2026-06-14 00:00:00', $range['from']->format('Y-m-d H:i:s'));
        self::assertSame('2026-07-14 15:30:00', $range['to']->format('Y-m-d H:i:s'));
    }

    public function testGetDateRangeAllReturnsNullFrom(): void
    {
        $now = new \DateTime('2026-07-14 15:30:00', new \DateTimeZone('UTC'));
        $range = $this->periodSource->getDateRange('all', $now);

        self::assertNull($range['from']);
        self::assertSame('2026-07-14 15:30:00', $range['to']->format('Y-m-d H:i:s'));
    }
}
