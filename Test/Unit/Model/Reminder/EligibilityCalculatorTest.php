<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Reminder;

use MageOS\DigitalSignature\Model\Document\Status;
use MageOS\DigitalSignature\Model\Reminder\EligibilityCalculator;
use MageOS\DigitalSignature\TestSupport\FakeDocument;
use PHPUnit\Framework\TestCase;

class EligibilityCalculatorTest extends TestCase
{
    private EligibilityCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new EligibilityCalculator();
    }

    public function testCustomerReminderNotSentWhenDisabled(): void
    {
        $document = (new FakeDocument())
            ->setStatus(Status::SENT)
            ->setUpdatedAt('2026-07-10 12:00:00');

        $now = new \DateTime('2026-07-14 12:00:00', new \DateTimeZone('UTC'));

        $result = $this->calculator->shouldSendCustomerReminder(
            $document,
            $now,
            false, // enabled
            3,     // threshold days
            3,     // interval days
            2      // max reminders
        );

        self::assertFalse($result);
    }

    public function testCustomerReminderNotSentWhenNotSentStatus(): void
    {
        $document = (new FakeDocument())
            ->setStatus(Status::GENERATED)
            ->setUpdatedAt('2026-07-10 12:00:00');

        $now = new \DateTime('2026-07-14 12:00:00', new \DateTimeZone('UTC'));

        $result = $this->calculator->shouldSendCustomerReminder(
            $document,
            $now,
            true,  // enabled
            3,     // threshold days
            3,     // interval days
            2      // max reminders
        );

        self::assertFalse($result);
    }

    public function testCustomerReminderNotSentWhenInactive(): void
    {
        $document = (new FakeDocument())
            ->setStatus(Status::SENT)
            ->setIsActive(false)
            ->setUpdatedAt('2026-07-10 12:00:00');

        $now = new \DateTime('2026-07-14 12:00:00', new \DateTimeZone('UTC'));

        $result = $this->calculator->shouldSendCustomerReminder(
            $document,
            $now,
            true,  // enabled
            3,     // threshold days
            3,     // interval days
            2      // max reminders
        );

        self::assertFalse($result);
    }

    public function testCustomerReminderNotSentWhenMaxReached(): void
    {
        $document = (new FakeDocument())
            ->setStatus(Status::SENT)
            ->setReminderCount(2)
            ->setUpdatedAt('2026-07-10 12:00:00');

        $now = new \DateTime('2026-07-14 12:00:00', new \DateTimeZone('UTC'));

        $result = $this->calculator->shouldSendCustomerReminder(
            $document,
            $now,
            true,  // enabled
            3,     // threshold days
            3,     // interval days
            2      // max reminders
        );

        self::assertFalse($result);
    }

    public function testCustomerReminderNotSentWhenThresholdNotMet(): void
    {
        $document = (new FakeDocument())
            ->setStatus(Status::SENT)
            ->setUpdatedAt('2026-07-13 12:00:00');

        $now = new \DateTime('2026-07-14 12:00:00', new \DateTimeZone('UTC'));

        $result = $this->calculator->shouldSendCustomerReminder(
            $document,
            $now,
            true,  // enabled
            3,     // threshold days
            3,     // interval days
            2      // max reminders
        );

        self::assertFalse($result);
    }

    public function testCustomerReminderSentWhenThresholdMetFirstTime(): void
    {
        $document = (new FakeDocument())
            ->setStatus(Status::SENT)
            ->setReminderCount(0)
            ->setUpdatedAt('2026-07-11 12:00:00'); // 3 days ago

        $now = new \DateTime('2026-07-14 12:00:00', new \DateTimeZone('UTC'));

        $result = $this->calculator->shouldSendCustomerReminder(
            $document,
            $now,
            true,  // enabled
            3,     // threshold days
            3,     // interval days
            2      // max reminders
        );

        self::assertTrue($result);
    }

    public function testCustomerReminderNotSentWhenIntervalNotMet(): void
    {
        $document = (new FakeDocument())
            ->setStatus(Status::SENT)
            ->setReminderCount(1)
            ->setUpdatedAt('2026-07-08 12:00:00') // 6 days ago
            ->setLastReminderAt('2026-07-13 12:00:00'); // 1 day ago

        $now = new \DateTime('2026-07-14 12:00:00', new \DateTimeZone('UTC'));

        $result = $this->calculator->shouldSendCustomerReminder(
            $document,
            $now,
            true,  // enabled
            3,     // threshold days
            3,     // interval days
            2      // max reminders
        );

        self::assertFalse($result);
    }

    public function testCustomerReminderSentWhenIntervalMet(): void
    {
        $document = (new FakeDocument())
            ->setStatus(Status::SENT)
            ->setReminderCount(1)
            ->setUpdatedAt('2026-07-08 12:00:00') // 6 days ago
            ->setLastReminderAt('2026-07-11 12:00:00'); // 3 days ago

        $now = new \DateTime('2026-07-14 12:00:00', new \DateTimeZone('UTC'));

        $result = $this->calculator->shouldSendCustomerReminder(
            $document,
            $now,
            true,  // enabled
            3,     // threshold days
            3,     // interval days
            2      // max reminders
        );

        self::assertTrue($result);
    }

    public function testAdminEscalationNotSentWhenDisabled(): void
    {
        $document = (new FakeDocument())
            ->setStatus(Status::SENT)
            ->setUpdatedAt('2026-07-05 12:00:00');

        $now = new \DateTime('2026-07-14 12:00:00', new \DateTimeZone('UTC'));

        $result = $this->calculator->shouldSendAdminEscalation(
            $document,
            $now,
            false, // enabled
            7      // threshold days
        );

        self::assertFalse($result);
    }

    public function testAdminEscalationNotSentWhenAlreadySent(): void
    {
        $document = (new FakeDocument())
            ->setStatus(Status::SENT)
            ->setUpdatedAt('2026-07-05 12:00:00')
            ->setEscalationSentAt('2026-07-12 12:00:00');

        $now = new \DateTime('2026-07-14 12:00:00', new \DateTimeZone('UTC'));

        $result = $this->calculator->shouldSendAdminEscalation(
            $document,
            $now,
            true,  // enabled
            7      // threshold days
        );

        self::assertFalse($result);
    }

    public function testAdminEscalationNotSentWhenThresholdNotMet(): void
    {
        $document = (new FakeDocument())
            ->setStatus(Status::SENT)
            ->setUpdatedAt('2026-07-10 12:00:00'); // 4 days ago

        $now = new \DateTime('2026-07-14 12:00:00', new \DateTimeZone('UTC'));

        $result = $this->calculator->shouldSendAdminEscalation(
            $document,
            $now,
            true,  // enabled
            7      // threshold days
        );

        self::assertFalse($result);
    }

    public function testAdminEscalationSentWhenThresholdMet(): void
    {
        $document = (new FakeDocument())
            ->setStatus(Status::SENT)
            ->setUpdatedAt('2026-07-07 12:00:00'); // 7 days ago

        $now = new \DateTime('2026-07-14 12:00:00', new \DateTimeZone('UTC'));

        $result = $this->calculator->shouldSendAdminEscalation(
            $document,
            $now,
            true,  // enabled
            7      // threshold days
        );

        self::assertTrue($result);
    }
}
