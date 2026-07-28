<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Pdf\Xref;

use MageOS\DigitalSignature\Model\Pdf\Xref\XrefStreamReader;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class XrefStreamReaderTest extends TestCase
{
    use XrefStreamFixtureTrait;

    private XrefStreamReader $reader;

    protected function setUp(): void
    {
        $this->reader = new XrefStreamReader();
    }

    /**
     * Applies the PNG "Up" filter (type 2) row by row: each row is
     * preceded by the filter-type byte and the bytes are the difference relative to
     * the previous row (0 for the first row) - exact inverse of the
     * "Up" decoding in XrefStreamReader.
     */
    private function applyPngUpFilter(string $rows, int $columns): string
    {
        $out = '';
        $prev = str_repeat("\x00", $columns);
        $rowCount = intdiv(strlen($rows), $columns);
        for ($r = 0; $r < $rowCount; $r++) {
            $row = substr($rows, $r * $columns, $columns);
            $out .= chr(2);
            for ($x = 0; $x < $columns; $x++) {
                $out .= chr((ord($row[$x]) - ord($prev[$x])) & 0xFF);
            }
            $prev = $row;
        }

        return $out;
    }

    private function buildXrefStreamPdf(string $dictExtra, string $streamData): string
    {
        return "%PDF-1.7\n"
            . "1 0 obj\n<<{$dictExtra} /Length " . strlen($streamData) . ">>\nstream\n"
            . $streamData . "\nendstream\nendobj\nstartxref\n9\n%%EOF\n";
    }

    public function testReadsUncompressedStreamWithoutFilter(): void
    {
        $rows = $this->buildXrefStreamRows([[1, 100, 0], [1, 200, 0]], 1, 4, 2);
        $pdf = $this->buildXrefStreamPdf('/Type /XRef /Size 3 /Root 2 0 R /W [1 4 2] /Index [1 2]', $rows);

        $link = $this->reader->readAt($pdf, strpos($pdf, '1 0 obj'));

        self::assertSame(3, $link->size);
        self::assertSame('2 0 R', $link->root);
        self::assertTrue($link->isStream);
        self::assertFalse($link->hasObjectStreams);
        self::assertFalse($link->hasEncrypt);
        self::assertNull($link->prevOffset);
    }

    public function testReadsFlateCompressedStreamWithPngUpPredictor(): void
    {
        $rows = $this->buildXrefStreamRows([[1, 100, 0], [1, 200, 0], [1, 300, 0]], 1, 4, 2);
        $predicted = $this->applyPngUpFilter($rows, 7); // columns = sum(W) = 1+4+2
        $compressed = gzcompress($predicted, 9);
        $pdf = $this->buildXrefStreamPdf(
            '/Type /XRef /Size 4 /Root 2 0 R /W [1 4 2] /Index [1 3] '
            . '/Filter /FlateDecode /DecodeParms << /Predictor 12 /Columns 7 >>',
            $compressed
        );

        $link = $this->reader->readAt($pdf, strpos($pdf, '1 0 obj'));

        self::assertSame(4, $link->size);
        self::assertFalse($link->hasObjectStreams);
    }

    public function testDetectsObjectStreamEntries(): void
    {
        // type 2 = object compressed inside an object stream (container num, index)
        $rows = $this->buildXrefStreamRows([[1, 100, 0], [2, 5, 0]], 1, 4, 2);
        $pdf = $this->buildXrefStreamPdf('/Type /XRef /Size 3 /Root 2 0 R /W [1 4 2] /Index [1 2]', $rows);

        $link = $this->reader->readAt($pdf, strpos($pdf, '1 0 obj'));

        self::assertTrue($link->hasObjectStreams);
    }

    public function testDetectsEncrypt(): void
    {
        $rows = $this->buildXrefStreamRows([[1, 100, 0]], 1, 4, 2);
        $pdf = $this->buildXrefStreamPdf(
            '/Type /XRef /Size 2 /Root 2 0 R /W [1 4 2] /Index [1 1] /Encrypt 5 0 R',
            $rows
        );

        $link = $this->reader->readAt($pdf, strpos($pdf, '1 0 obj'));

        self::assertTrue($link->hasEncrypt);
    }

    public function testReadsPrevOffset(): void
    {
        $rows = $this->buildXrefStreamRows([[1, 100, 0]], 1, 4, 2);
        $pdf = $this->buildXrefStreamPdf(
            '/Type /XRef /Size 2 /Root 2 0 R /W [1 4 2] /Index [1 1] /Prev 42',
            $rows
        );

        $link = $this->reader->readAt($pdf, strpos($pdf, '1 0 obj'));

        self::assertSame(42, $link->prevOffset);
    }

    public function testReadsMultipleIndexRanges(): void
    {
        $rows = $this->buildXrefStreamRows([[1, 100, 0], [1, 200, 0], [1, 300, 0]], 1, 4, 2);
        $pdf = $this->buildXrefStreamPdf('/Type /XRef /Size 10 /Root 2 0 R /W [1 4 2] /Index [1 1 5 2]', $rows);

        $link = $this->reader->readAt($pdf, strpos($pdf, '1 0 obj'));

        self::assertSame(10, $link->size);
        self::assertFalse($link->hasObjectStreams);
    }

    public function testRejectsUnsupportedPredictor(): void
    {
        $rows = $this->buildXrefStreamRows([[1, 100, 0]], 1, 4, 2);
        $compressed = gzcompress($rows, 9);
        $pdf = $this->buildXrefStreamPdf(
            '/Type /XRef /Size 2 /Root 2 0 R /W [1 4 2] /Index [1 1] '
            . '/Filter /FlateDecode /DecodeParms << /Predictor 2 /Columns 7 >>',
            $compressed
        );

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/predictor/');

        $this->reader->readAt($pdf, strpos($pdf, '1 0 obj'));
    }

    public function testRejectsPredictorAboveValidRange(): void
    {
        $rows = $this->buildXrefStreamRows([[1, 100, 0]], 1, 4, 2);
        $compressed = gzcompress($rows, 9);
        $pdf = $this->buildXrefStreamPdf(
            '/Type /XRef /Size 2 /Root 2 0 R /W [1 4 2] /Index [1 1] '
            . '/Filter /FlateDecode /DecodeParms << /Predictor 16 /Columns 7 >>',
            $compressed
        );

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/predictor/');

        $this->reader->readAt($pdf, strpos($pdf, '1 0 obj'));
    }

    public function testThrowsWhenNotAnXRefObject(): void
    {
        $pdf = "1 0 obj\n<</Type /Catalog /Length 0>>\nstream\n\nendstream\nendobj\n";

        $this->expectException(LocalizedException::class);

        $this->reader->readAt($pdf, 0);
    }

    public function testExposesObjectOffsetsMappedFromIndex(): void
    {
        $rows = $this->buildXrefStreamRows([[1, 100, 0], [1, 200, 0]], 1, 4, 2);
        $pdf = $this->buildXrefStreamPdf('/Type /XRef /Size 3 /Root 2 0 R /W [1 4 2] /Index [1 2]', $rows);

        $link = $this->reader->readAt($pdf, strpos($pdf, '1 0 obj'));

        self::assertSame([1 => 100, 2 => 200], $link->objectOffsets);
    }

    public function testObjectOffsetsSkipObjectStreamEntries(): void
    {
        // row 1: object 1 with a direct offset; row 2: object 2 inside an object stream (type 2)
        $rows = $this->buildXrefStreamRows([[1, 100, 0], [2, 5, 0]], 1, 4, 2);
        $pdf = $this->buildXrefStreamPdf('/Type /XRef /Size 3 /Root 2 0 R /W [1 4 2] /Index [1 2]', $rows);

        $link = $this->reader->readAt($pdf, strpos($pdf, '1 0 obj'));

        self::assertSame([1 => 100], $link->objectOffsets);
        self::assertArrayNotHasKey(2, $link->objectOffsets);
    }

    public function testObjectOffsetsMapsMultipleIndexRangestoCorrectNumbers(): void
    {
        $rows = $this->buildXrefStreamRows([[1, 100, 0], [1, 200, 0], [1, 300, 0]], 1, 4, 2);
        $pdf = $this->buildXrefStreamPdf('/Type /XRef /Size 10 /Root 2 0 R /W [1 4 2] /Index [1 1 5 2]', $rows);

        $link = $this->reader->readAt($pdf, strpos($pdf, '1 0 obj'));

        self::assertSame([1 => 100, 5 => 200, 6 => 300], $link->objectOffsets);
    }
}
