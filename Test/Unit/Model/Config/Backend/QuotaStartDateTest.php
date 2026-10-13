<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Config\Backend;

use MageOS\DigitalSignature\Model\Config\Backend\QuotaStartDate;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QuotaStartDateTest extends TestCase
{
    private QuotaStartDate $model;

    protected function setUp(): void
    {
        // The real constructor needs the full Magento context: normalize() does not use it.
        $this->model = (new \ReflectionClass(QuotaStartDate::class))->newInstanceWithoutConstructor();
    }

    public function testAcceptsIsoDate(): void
    {
        self::assertSame('2026-09-01', $this->model->normalize('2026-09-01'));
    }

    public function testTrimsSurroundingSpaces(): void
    {
        self::assertSame('2026-09-01', $this->model->normalize('  2026-09-01 '));
    }

    public function testEmptyValueIsAllowed(): void
    {
        self::assertSame('', $this->model->normalize(''));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidDateProvider(): array
    {
        return [
            'not a date' => ['next monday'],
            'day first' => ['01/09/2026'],
            'impossible day' => ['2026-02-30'],
            'missing day' => ['2026-09'],
        ];
    }

    #[DataProvider('invalidDateProvider')]
    public function testRejectsAnythingButAValidIsoDate(string $value): void
    {
        $this->expectException(LocalizedException::class);

        $this->model->normalize($value);
    }
}
