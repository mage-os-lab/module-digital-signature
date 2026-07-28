<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Pdf;

use MageOS\DigitalSignature\Model\Pdf\IncrementalUpdateWriter;
use MageOS\DigitalSignature\Model\Pdf\TagReplacer;
use MageOS\DigitalSignature\Model\Pdf\Xref\ClassicXrefReader;
use MageOS\DigitalSignature\Model\Pdf\Xref\XrefChainResolver;
use MageOS\DigitalSignature\Model\Pdf\Xref\XrefStreamReader;
use MageOS\DigitalSignature\Test\Unit\Model\Pdf\Xref\XrefStreamFixtureTrait;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class TagReplacerTest extends TestCase
{
    use XrefStreamFixtureTrait;

    private const TAG = '{WSIGN#80,20#placeholder@example.com}';

    private TagReplacer $tagReplacer;
    private \PHPUnit\Framework\MockObject\MockObject $mergeFieldPoolMock;
    private \PHPUnit\Framework\MockObject\MockObject $previewOrderContextMock;
    private \PHPUnit\Framework\MockObject\MockObject $scopeConfigMock;

    protected function setUp(): void
    {
        $xrefResolver = new XrefChainResolver(new ClassicXrefReader(), new XrefStreamReader());
        $this->mergeFieldPoolMock = $this->createMock(\MageOS\DigitalSignature\Api\MergeFieldPoolInterface::class);
        $this->previewOrderContextMock = $this->createMock(\MageOS\DigitalSignature\Model\Pdf\PreviewOrderContext::class);
        $this->scopeConfigMock = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $this->tagReplacer = new TagReplacer(
            $xrefResolver,
            new IncrementalUpdateWriter($xrefResolver),
            $this->mergeFieldPoolMock,
            $this->previewOrderContextMock,
            $this->scopeConfigMock
        );
    }

    public function testFindTagsInPlainStream(): void
    {
        $pdf = $this->buildPdf('BT (' . self::TAG . ') Tj ET');

        self::assertSame([self::TAG], $this->tagReplacer->findTags($pdf));
    }

    public function testFindTagsInCompressedStream(): void
    {
        $pdf = $this->buildPdf('BT (' . self::TAG . ') Tj ET', true);

        self::assertSame([self::TAG], $this->tagReplacer->findTags($pdf));
    }

    public function testFindTagsReturnsEmptyWhenNoTagPresent(): void
    {
        $pdf = $this->buildPdf('BT (documento senza tag) Tj ET');

        self::assertSame([], $this->tagReplacer->findTags($pdf));
    }

    public function testFindTagsDetectsTagOutsideStreams(): void
    {
        $pdf = "%PDF-1.4\n" . self::TAG . "\ntrailer\n<</Size 1 /Root 1 0 R>>\nstartxref\n9\n%%EOF\n";

        self::assertSame([self::TAG], $this->tagReplacer->findTags($pdf));
    }

    public function testFindTagsDeduplicates(): void
    {
        $content = 'BT (' . self::TAG . ') Tj (' . self::TAG . ') Tj ET';
        $pdf = $this->buildPdf($content);

        self::assertSame([self::TAG], $this->tagReplacer->findTags($pdf));
    }

    public function testReplaceSignerEmailInPlainStream(): void
    {
        $pdf = $this->buildPdf('BT (' . self::TAG . ') Tj ET');

        $result = $this->tagReplacer->replaceSignerEmail($pdf, 'cliente.reale@example.com');

        self::assertStringStartsWith('%PDF', $result);
        // The placeholder must no longer exist anywhere (object whitened out)
        self::assertStringNotContainsString('placeholder@example.com', $result);
        // Round-trip: the updated tag is readable with the same parser
        self::assertSame(
            ['{WSIGN#80,20#cliente.reale@example.com}'],
            $this->tagReplacer->findTags($result)
        );
        // Incremental update: new xref linked to the previous one
        self::assertStringContainsString('/Prev', $result);
        self::assertSame(2, substr_count($result, 'startxref'));
    }

    public function testReplaceSignerEmailInCompressedStream(): void
    {
        $pdf = $this->buildPdf('BT (' . self::TAG . ') Tj ET', true);

        $result = $this->tagReplacer->replaceSignerEmail($pdf, 'cliente.reale@example.com');

        self::assertSame(
            ['{WSIGN#80,20#cliente.reale@example.com}'],
            $this->tagReplacer->findTags($result)
        );
    }

    public function testReplaceEscapesPdfStringDelimiters(): void
    {
        $pdf = $this->buildPdf('BT (' . self::TAG . ') Tj ET');

        $result = $this->tagReplacer->replaceSignerEmail($pdf, 'a(b)@example.com');

        // ( and ) are PDF string delimiters: they must come out escaped
        self::assertStringContainsString('{WSIGN#80,20#a\\(b\\)@example.com}', $result);
    }

    public function testReplaceThrowsOnNonPdfContent(): void
    {
        $this->expectException(LocalizedException::class);

        $this->tagReplacer->replaceSignerEmail('non sono un pdf', 'a@b.it');
    }

    public function testReplaceThrowsWhenNoTagPresent(): void
    {
        $pdf = $this->buildPdf('BT (documento senza tag) Tj ET');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/No signature tag found/');

        $this->tagReplacer->replaceSignerEmail($pdf, 'a@b.it');
    }

    public function testStreamUnderDecompressionCapIsParsed(): void
    {
        // Legitimate FlateDecode stream (small content): the tag is readable.
        $pdf = $this->buildPdf('BT (' . self::TAG . ') Tj ET', true);

        self::assertSame([self::TAG], $this->tagReplacer->findTags($pdf));
    }

    public function testDecompressionBombOverCapIsRejected(): void
    {
        // Stream with a valid tag followed by ~60 MB of padding: once the anti-bomb
        // cap is exceeded, gzuncompress fails and the whole object is discarded.
        // We'd rather lose a tag than decompress a hostile stream:
        // the result is "no tag" (without the cap the tag would instead be read).
        $content = self::TAG . str_repeat('A', 60 * 1024 * 1024);
        $compressed = gzcompress($content, 9);
        unset($content);
        $pdf = "%PDF-1.4\n1 0 obj\n<</Filter /FlateDecode /Length " . strlen($compressed) . ">>\n"
            . "stream\n" . $compressed . "\nendstream\nendobj\n"
            . "trailer\n<</Size 2 /Root 1 0 R>>\nstartxref\n9\n%%EOF\n";

        self::assertSame(
            [],
            $this->tagReplacer->findTags($pdf),
            'Lo stream oltre il cap deve essere scartato, non decompresso'
        );
    }

    public function testPathologicalInputsDoNotHang(): void
    {
        // ReDoS regression: long sequences must not degenerate into backtracking
        $start = microtime(true);
        $this->tagReplacer->findTags('%PDF-1.4\n{WSIGN#' . str_repeat('1,', 50000) . '#a@b.it');
        $this->tagReplacer->findTags('%PDF-1.4\n{WSIGN#1,1#' . str_repeat('x', 200000));
        self::assertLessThan(2.0, microtime(true) - $start, 'Il parsing non deve degenerare');
    }

    public function testReplaceThrowsWhenNoXrefRecognized(): void
    {
        // PDF with a tag but without any recognizable cross-reference structure
        // at the position indicated by startxref (neither a classic table, nor a
        // valid /Type /XRef object).
        $content = 'BT (' . self::TAG . ') Tj ET';
        $pdf = "%PDF-1.5\n4 0 obj\n<</Length " . strlen($content) . ">>\nstream\n"
            . $content . "\nendstream\nendobj\nstartxref\n9\n%%EOF\n";

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/XRef/');

        $this->tagReplacer->replaceSignerEmail($pdf, 'a@b.it');
    }

    public function testReplaceSignerEmailOnXrefStreamSource(): void
    {
        $header = "%PDF-1.7\n";
        $content = 'BT (' . self::TAG . ') Tj ET';
        $obj1Offset = strlen($header);
        $obj1 = "1 0 obj\n<</Length " . strlen($content) . ">>\nstream\n{$content}\nendstream\nendobj\n";
        $xrefOffset = strlen($header . $obj1);

        $rows = $this->buildXrefStreamRows(
            [[1, $obj1Offset, 0], [1, $xrefOffset, 0]],
            1,
            4,
            2
        );
        $xrefObj = "2 0 obj\n<</Type /XRef /Size 3 /Root 1 0 R /W [1 4 2] /Index [1 2]"
            . ' /Length ' . strlen($rows) . ">>\nstream\n{$rows}\nendstream\nendobj\n";

        $pdf = $header . $obj1 . $xrefObj . "startxref\n{$xrefOffset}\n%%EOF\n";

        $result = $this->tagReplacer->replaceSignerEmail($pdf, 'cliente.reale@example.com');

        self::assertStringNotContainsString('placeholder@example.com', $result);
        self::assertSame(
            ['{WSIGN#80,20#cliente.reale@example.com}'],
            $this->tagReplacer->findTags($result)
        );
        self::assertStringContainsString('/Type /XRef', $result);
        self::assertSame(2, substr_count($result, 'startxref'));
    }

    public function testReplaceSignerEmailOnXrefStreamSourceRoundTripsThroughResolver(): void
    {
        $header = "%PDF-1.7\n";
        $content = 'BT (' . self::TAG . ') Tj ET';
        $obj1Offset = strlen($header);
        $obj1 = "1 0 obj\n<</Length " . strlen($content) . ">>\nstream\n{$content}\nendstream\nendobj\n";
        $xrefOffset = strlen($header . $obj1);

        $rows = $this->buildXrefStreamRows(
            [[1, $obj1Offset, 0], [1, $xrefOffset, 0]],
            1,
            4,
            2
        );
        $xrefObj = "2 0 obj\n<</Type /XRef /Size 3 /Root 1 0 R /W [1 4 2] /Index [1 2]"
            . ' /Length ' . strlen($rows) . ">>\nstream\n{$rows}\nendstream\nendobj\n";

        $pdf = $header . $obj1 . $xrefObj . "startxref\n{$xrefOffset}\n%%EOF\n";

        $result = $this->tagReplacer->replaceSignerEmail($pdf, 'cliente.reale@example.com');

        $resolver = new XrefChainResolver(new ClassicXrefReader(), new XrefStreamReader());
        $info = $resolver->resolve($result);

        // The new revision appended by the writer is itself an xref
        // stream: the resolver must recognize it by actually re-reading the block
        // just produced, not merely checking substrings in the output.
        self::assertTrue($info->isStreamBased);
        self::assertFalse($info->hasObjectStreams);
        self::assertFalse($info->isEncrypted);
        // buildXrefStreamUpdate() allocates the new xref object with number
        // = old Size (3) and declares /Size = old Size + 1 (4).
        self::assertSame(4, $info->size);
    }

    /**
     * Real fixture (LibreOffice + pikepdf/qpdf) with a genuine xref stream
     * (no object stream) and a signature tag injected into a real
     * FlateDecode content stream: covers the "no real PDF fixture" gap flagged
     * by the final review. See src/Test/Unit/Model/Pdf/_fixtures/.
     */
    public function testReplaceSignerEmailOnRealXrefStreamFixture(): void
    {
        $pdf = file_get_contents(__DIR__ . '/_fixtures/real-xref-stream.pdf');

        $result = $this->tagReplacer->replaceSignerEmail($pdf, 'cliente.reale@example.com');

        self::assertSame(
            ['{WSIGN#80,20#cliente.reale@example.com}'],
            $this->tagReplacer->findTags($result)
        );

        $resolver = new XrefChainResolver(new ClassicXrefReader(), new XrefStreamReader());
        $info = $resolver->resolve($result);
        self::assertTrue($info->isStreamBased);
        self::assertFalse($info->hasObjectStreams);
    }

    public function testReplaceSignerEmailOnRealClassicXrefFixture(): void
    {
        $pdf = file_get_contents(__DIR__ . '/_fixtures/real-classic-xref.pdf');

        $result = $this->tagReplacer->replaceSignerEmail($pdf, 'cliente.reale@example.com');

        self::assertSame(
            ['{WSIGN#80,20#cliente.reale@example.com}'],
            $this->tagReplacer->findTags($result)
        );
    }

    public function testFindFieldTags(): void
    {
        $pdf = $this->buildPdf('BT ({FIELD:order_number} {FIELD:grand_total}) Tj ET');
        self::assertSame(['order_number', 'grand_total'], $this->tagReplacer->findFieldTags($pdf));
    }

    public function testReplaceMergeFields(): void
    {
        $pdf = $this->buildPdf('BT (' . self::TAG . ' {FIELD:order_number}) Tj ET');

        $contextMock = $this->createMock(\MageOS\DigitalSignature\Model\MergeField\Context::class);
        $providerMock = $this->createMock(\MageOS\DigitalSignature\Api\MergeFieldProviderInterface::class);

        $providerMock->expects(self::once())
            ->method('resolve')
            ->with($contextMock)
            ->willReturn('100000001');

        $this->mergeFieldPoolMock->expects(self::once())
            ->method('get')
            ->with('order_number')
            ->willReturn($providerMock);

        $result = $this->tagReplacer->replaceSignerEmail($pdf, 'cliente.reale@example.com', $contextMock);

        self::assertStringContainsString('100000001', $result);
        self::assertStringContainsString('cliente.reale@example.com', $result);
    }

    public function testReplaceSignerEmailAppliesConfiguredTagColorDuringRealGeneration(): void
    {
        $contextMock = $this->createMock(\MageOS\DigitalSignature\Model\MergeField\Context::class);
        $contextMock->method('getStoreId')->willReturn(0);

        $this->scopeConfigMock->expects(self::once())
            ->method('getValue')
            ->with(
                'digital_signature/general/builder_tag_color',
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                0
            )
            ->willReturn('1 0 0 rg');

        // Typical content injected by the builder
        $streamContent = 'q BT /MDSHelv1 10 Tf 100 100 Td ({WSIGN#80,20#placeholder@example.com}) Tj ET Q';
        $pdf = $this->buildPdf($streamContent);

        // A non-null context represents the real document generation for an
        // order (see DocumentProcessor): the configured color must only be applied here.
        $result = $this->tagReplacer->replaceSignerEmail($pdf, 'cliente.reale@example.com', $contextMock);

        // The modified content stream string must contain the color operator
        self::assertStringContainsString('q 1 0 0 rg BT /MDSHelv1', $result);
    }

    public function testReplaceSignerEmailKeepsTagVisibleWithoutContextForPreview(): void
    {
        $this->scopeConfigMock->expects(self::never())->method('getValue');

        // Same builder-injected content as the real generation, but without context:
        // represents the preview (Preview.php) and the validation dry-run
        // (TemplateValidator), which always pass null. The tag must remain
        // visible to allow the merchant to verify its position.
        $streamContent = 'q BT /MDSHelv1 10 Tf 100 100 Td ({WSIGN#80,20#placeholder@example.com}) Tj ET Q';
        $pdf = $this->buildPdf($streamContent);

        $result = $this->tagReplacer->replaceSignerEmail($pdf, 'cliente.reale@example.com');

        self::assertStringContainsString('q BT /MDSHelv1', $result);
        self::assertStringNotContainsString('rg BT /MDSHelv1', $result);
        self::assertStringNotContainsString('g BT /MDSHelv1', $result);
    }

    /**
     * Minimal PDF 1.4 with a content stream and a classic trailer. The xref
     * offsets are fake: TagReplacer only uses startxref and trailer.
     */
    private function buildPdf(string $streamContent, bool $compressed = false): string
    {
        $data = $compressed ? gzcompress($streamContent, 9) : $streamContent;
        $dict = $compressed
            ? sprintf('<</Filter /FlateDecode /Length %d>>', strlen($data))
            : sprintf('<</Length %d>>', strlen($data));

        $pdf = "%PDF-1.4\n";
        $pdf .= "1 0 obj\n<</Type /Catalog /Pages 2 0 R>>\nendobj\n";
        $pdf .= "2 0 obj\n<</Type /Pages /Kids [3 0 R] /Count 1>>\nendobj\n";
        $pdf .= "3 0 obj\n<</Type /Page /Parent 2 0 R /Contents 4 0 R>>\nendobj\n";
        $pdf .= "4 0 obj\n{$dict}\nstream\n{$data}\nendstream\nendobj\n";
        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 5\n0000000000 65535 f \n" . str_repeat("0000000000 00000 n \n", 4);
        $pdf .= "trailer\n<</Size 5 /Root 1 0 R>>\nstartxref\n{$xrefPos}\n%%EOF\n";

        return $pdf;
    }
}
