<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Pdf\Xref;

use MageOS\DigitalSignature\Model\Pdf\Xref\ClassicXrefReader;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class ClassicXrefReaderTest extends TestCase
{
    private ClassicXrefReader $reader;

    protected function setUp(): void
    {
        $this->reader = new ClassicXrefReader();
    }

    public function testReadsTrailerFields(): void
    {
        $pdf = "%PDF-1.4\n"
            . "xref\n0 1\n0000000000 65535 f \n"
            . "trailer\n<</Size 5 /Root 1 0 R>>\nstartxref\n9\n%%EOF\n";
        $offset = strpos($pdf, 'xref');

        $link = $this->reader->readAt($pdf, $offset);

        self::assertSame(5, $link->size);
        self::assertSame('1 0 R', $link->root);
        self::assertFalse($link->hasEncrypt);
        self::assertFalse($link->hasObjectStreams);
        self::assertFalse($link->isStream);
        self::assertNull($link->prevOffset);
    }

    public function testReadsPrevAndEncrypt(): void
    {
        $pdf = "xref\n0 1\n0000000000 65535 f \n"
            . "trailer\n<</Size 5 /Root 1 0 R /Prev 123 /Encrypt 3 0 R>>\nstartxref\n0\n%%EOF\n";

        $link = $this->reader->readAt($pdf, 0);

        self::assertSame(123, $link->prevOffset);
        self::assertTrue($link->hasEncrypt);
    }

    public function testThrowsWhenNotAtXrefKeyword(): void
    {
        $this->expectException(LocalizedException::class);

        $this->reader->readAt("%PDF-1.4\nnot xref here", 0);
    }

    public function testThrowsWhenTrailerMissingSizeOrRoot(): void
    {
        $pdf = "xref\n0 1\n0000000000 65535 f \ntrailer\n<</Root 1 0 R>>\n";

        $this->expectException(LocalizedException::class);

        $this->reader->readAt($pdf, 0);
    }

    public function testExposesObjectOffsets(): void
    {
        $pdf = "%PDF-1.4\n"
            . "xref\n0 3\n"
            . "0000000000 65535 f \n"
            . "0000000015 00000 n \n"
            . "0000000074 00000 n \n"
            . "trailer\n<</Size 3 /Root 1 0 R>>\nstartxref\n9\n%%EOF\n";
        $offset = strpos($pdf, 'xref');

        $link = $this->reader->readAt($pdf, $offset);

        self::assertSame([1 => 15, 2 => 74], $link->objectOffsets);
    }

    public function testObjectOffsetsSkipsFreeEntries(): void
    {
        $pdf = "xref\n0 2\n"
            . "0000000000 65535 f \n"
            . "0000000015 00000 n \n"
            . "trailer\n<</Size 2 /Root 1 0 R>>\nstartxref\n0\n%%EOF\n";

        $link = $this->reader->readAt($pdf, 0);

        self::assertSame([1 => 15], $link->objectOffsets);
        self::assertArrayNotHasKey(0, $link->objectOffsets);
    }

    public function testObjectOffsetsHandlesMultipleSubsections(): void
    {
        $pdf = "xref\n"
            . "0 1\n0000000000 65535 f \n"
            . "3 2\n0000000200 00000 n \n0000000350 00000 n \n"
            . "trailer\n<</Size 5 /Root 1 0 R>>\nstartxref\n0\n%%EOF\n";

        $link = $this->reader->readAt($pdf, 0);

        self::assertSame([3 => 200, 4 => 350], $link->objectOffsets);
    }

    public function testObjectOffsetsSkipsMalformedEntryLinesWithoutThrowing(): void
    {
        // La seconda entry ha un offset di 9 cifre invece di 10 (formato non
        // conforme): viene scartata silenziosamente, non solleva eccezione.
        // Comportamento intenzionale (allineato ai lettori PDF reali, tolleranti
        // verso imperfezioni di formattazione nella tabella xref testuale).
        $pdf = "xref\n0 3\n"
            . "0000000000 65535 f \n"
            . "000000015 00000 n \n"
            . "0000000074 00000 n \n"
            . "trailer\n<</Size 3 /Root 1 0 R>>\nstartxref\n9\n%%EOF\n";
        $offset = strpos($pdf, 'xref');

        $link = $this->reader->readAt($pdf, $offset);

        self::assertSame([2 => 74], $link->objectOffsets);
        self::assertArrayNotHasKey(1, $link->objectOffsets);
    }
}
