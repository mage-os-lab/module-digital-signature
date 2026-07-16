<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Pdf\Xref;

use MageOS\DigitalSignature\Model\Pdf\Xref\StartxrefLocator;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class StartxrefLocatorTest extends TestCase
{
    public function testLocatesLastStartxref(): void
    {
        $pdf = "%PDF-1.4\n...\nstartxref\n123\n%%EOF\n...\nstartxref\n456\n%%EOF\n";

        self::assertSame(456, StartxrefLocator::locate($pdf));
    }

    public function testThrowsWhenMissing(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/startxref non trovato/');

        StartxrefLocator::locate("%PDF-1.4\nsenza xref\n");
    }
}
