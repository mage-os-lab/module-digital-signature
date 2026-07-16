<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Document;

use MageOS\DigitalSignature\Model\Document\Status;
use PHPUnit\Framework\TestCase;

class StatusTest extends TestCase
{
    public function testFinalStates(): void
    {
        self::assertTrue(Status::isFinal(Status::SIGNED));
        self::assertTrue(Status::isFinal(Status::DECLINED));
        self::assertTrue(Status::isFinal(Status::EXPIRED));
        self::assertTrue(Status::isFinal(Status::CANCELED));
    }

    public function testNonFinalStates(): void
    {
        self::assertFalse(Status::isFinal(Status::PENDING));
        self::assertFalse(Status::isFinal(Status::GENERATED));
        self::assertFalse(Status::isFinal(Status::SENT));
        self::assertFalse(Status::isFinal(Status::ERROR));
        self::assertFalse(Status::isFinal('sconosciuto'));
    }

    public function testEveryStatusHasALabel(): void
    {
        $labels = Status::getLabels();

        foreach ([
            Status::PENDING,
            Status::GENERATED,
            Status::SENT,
            Status::SIGNED,
            Status::DECLINED,
            Status::EXPIRED,
            Status::ERROR,
            Status::CANCELED,
        ] as $status) {
            self::assertArrayHasKey($status, $labels);
        }
    }

    public function testToOptionArrayShape(): void
    {
        $options = (new Status())->toOptionArray();

        self::assertCount(8, $options);
        foreach ($options as $option) {
            self::assertArrayHasKey('value', $option);
            self::assertArrayHasKey('label', $option);
        }
    }
}
