<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Webhook;

use MageOS\DigitalSignature\Model\Webhook\BackoffCalculator;
use PHPUnit\Framework\TestCase;

class BackoffCalculatorTest extends TestCase
{
    private BackoffCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new BackoffCalculator();
    }

    public function testScheduleGrowsWithAttempts(): void
    {
        self::assertSame(1, $this->calculator->nextAttemptDelayMinutes(1));
        self::assertSame(5, $this->calculator->nextAttemptDelayMinutes(2));
        self::assertSame(30, $this->calculator->nextAttemptDelayMinutes(3));
        self::assertSame(120, $this->calculator->nextAttemptDelayMinutes(4));
    }

    public function testCapsAtLastScheduleEntryBeyondConfiguredAttempts(): void
    {
        self::assertSame(120, $this->calculator->nextAttemptDelayMinutes(10));
    }

    public function testNeverReturnsBelowFirstEntryForZeroOrNegativeAttempts(): void
    {
        self::assertSame(1, $this->calculator->nextAttemptDelayMinutes(0));
    }
}
