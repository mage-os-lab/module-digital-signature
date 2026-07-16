<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Pdf\Xref;

use MageOS\DigitalSignature\Model\Pdf\Xref\ClassicXrefReader;
use MageOS\DigitalSignature\Model\Pdf\Xref\XrefChainResolver;
use MageOS\DigitalSignature\Model\Pdf\Xref\XrefStreamReader;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class XrefChainResolverTest extends TestCase
{
    use XrefStreamFixtureTrait;

    private XrefChainResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new XrefChainResolver(new ClassicXrefReader(), new XrefStreamReader());
    }

    public function testResolvesClassicOnlyFile(): void
    {
        $pdf = "%PDF-1.4\n"
            . "xref\n0 1\n0000000000 65535 f \n"
            . "trailer\n<</Size 5 /Root 1 0 R>>\nstartxref\n9\n%%EOF\n";

        $info = $this->resolver->resolve($pdf);

        self::assertSame(5, $info->size);
        self::assertSame('1 0 R', $info->root);
        self::assertFalse($info->isStreamBased);
        self::assertFalse($info->hasObjectStreams);
        self::assertFalse($info->isEncrypted);
    }

    public function testResolvesStreamOnlyFile(): void
    {
        $rows = $this->buildXrefStreamRows([[1, 100, 0]], 1, 4, 2);
        $header = "%PDF-1.7\n";
        $body = "1 0 obj\n<</Type /XRef /Size 2 /Root 2 0 R /W [1 4 2] /Index [1 1]"
            . ' /Length ' . strlen($rows) . ">>\nstream\n" . $rows . "\nendstream\nendobj\n";
        $offset = strlen($header);
        $pdf = $header . $body . "startxref\n{$offset}\n%%EOF\n";

        self::assertSame($offset, strpos($pdf, '1 0 obj'));

        $info = $this->resolver->resolve($pdf);

        self::assertSame(2, $info->size);
        self::assertTrue($info->isStreamBased);
    }

    public function testAggregatesObjectStreamsFromCurrentRevision(): void
    {
        $rows = $this->buildXrefStreamRows([[2, 5, 0]], 1, 4, 2);
        $classicSection = "xref\n0 1\n0000000000 65535 f \ntrailer\n<</Size 3 /Root 1 0 R>>\n";
        $streamOffset = strlen($classicSection);
        $pdf = $classicSection
            . '1 0 obj' . "\n<</Type /XRef /Size 4 /Root 2 0 R /W [1 4 2] /Index [1 1] /Prev 0"
            . ' /Length ' . strlen($rows) . ">>\nstream\n" . $rows . "\nendstream\nendobj\n"
            . "startxref\n{$streamOffset}\n%%EOF\n";

        $info = $this->resolver->resolve($pdf);

        self::assertTrue($info->hasObjectStreams);
        self::assertTrue($info->isStreamBased);
    }

    public function testRejectsChainLongerThanLimit(): void
    {
        $link1 = "xref\n0 1\n0000000000 65535 f \ntrailer\n<</Size 1 /Root 1 0 R>>\n";
        $link2Offset = strlen($link1);
        $link2 = "xref\n0 1\n0000000000 65535 f \ntrailer\n<</Size 2 /Root 1 0 R /Prev 0>>\n";
        $link3Offset = $link2Offset + strlen($link2);
        $link3 = "xref\n0 1\n0000000000 65535 f \ntrailer\n<</Size 3 /Root 1 0 R /Prev {$link2Offset}>>\n";
        $pdf = $link1 . $link2 . $link3 . "startxref\n{$link3Offset}\n%%EOF\n";

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/troppe revisioni/');

        $this->resolver->resolve($pdf);
    }

    public function testExposesObjectOffsetsFromCurrentRevision(): void
    {
        $pdf = "%PDF-1.4\n"
            . "xref\n0 2\n0000000000 65535 f \n0000000042 00000 n \n"
            . "trailer\n<</Size 2 /Root 1 0 R>>\nstartxref\n9\n%%EOF\n";

        $info = $this->resolver->resolve($pdf);

        self::assertSame([1 => 42], $info->objectOffsets);
    }

    public function testMergesObjectOffsetsAcrossPrevChainWithNewestWinning(): void
    {
        // Revisione precedente: oggetto 1 all'offset 42, oggetto 2 all'offset 99.
        $prev = "xref\n0 3\n0000000000 65535 f \n0000000042 00000 n \n0000000099 00000 n \n"
            . "trailer\n<</Size 3 /Root 1 0 R>>\n";
        $prevOffset = 0;
        // Revisione corrente: oggetto 1 aggiornato all'offset 500 (l'oggetto 2 resta
        // valido solo nella revisione precedente e deve comunque comparire nella mappa fusa).
        $current = "xref\n0 1\n0000000000 65535 f \n"
            . "1 1\n0000000500 00000 n \n"
            . "trailer\n<</Size 3 /Root 1 0 R /Prev {$prevOffset}>>\n";
        $currentOffset = strlen($prev);
        $pdf = $prev . $current . "startxref\n{$currentOffset}\n%%EOF\n";

        $info = $this->resolver->resolve($pdf);

        self::assertSame([1 => 500, 2 => 99], $info->objectOffsets);
    }
}
