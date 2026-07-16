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
        // Il placeholder non deve più esistere da nessuna parte (oggetto sbiancato)
        self::assertStringNotContainsString('placeholder@example.com', $result);
        // Round-trip: il tag aggiornato è leggibile con lo stesso parser
        self::assertSame(
            ['{WSIGN#80,20#cliente.reale@example.com}'],
            $this->tagReplacer->findTags($result)
        );
        // Incremental update: nuova xref collegata alla precedente
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

        // ( e ) sono delimitatori di stringa PDF: devono uscire escapati
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
        $this->expectExceptionMessageMatches('/Nessun tag firma/');

        $this->tagReplacer->replaceSignerEmail($pdf, 'a@b.it');
    }

    public function testStreamUnderDecompressionCapIsParsed(): void
    {
        // Stream FlateDecode legittimo (contenuto piccolo): il tag si legge.
        $pdf = $this->buildPdf('BT (' . self::TAG . ') Tj ET', true);

        self::assertSame([self::TAG], $this->tagReplacer->findTags($pdf));
    }

    public function testDecompressionBombOverCapIsRejected(): void
    {
        // Stream con un tag valido seguito da ~60 MB di padding: superato il cap
        // anti-bomba, gzuncompress fallisce e l'intero oggetto viene scartato.
        // Preferiamo perdere un tag piuttosto che decomprimere uno stream ostile:
        // il risultato è "nessun tag" (senza il cap il tag verrebbe invece letto).
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
        // Regressione ReDoS: sequenze lunghe non devono degenerare in backtracking
        $start = microtime(true);
        $this->tagReplacer->findTags('%PDF-1.4\n{WSIGN#' . str_repeat('1,', 50000) . '#a@b.it');
        $this->tagReplacer->findTags('%PDF-1.4\n{WSIGN#1,1#' . str_repeat('x', 200000));
        self::assertLessThan(2.0, microtime(true) - $start, 'Il parsing non deve degenerare');
    }

    public function testReplaceThrowsWhenNoXrefRecognized(): void
    {
        // PDF con tag ma senza alcuna struttura cross-reference riconoscibile
        // alla posizione indicata da startxref (né tabella classica, né un
        // oggetto /Type /XRef valido).
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

        // La nuova revisione appesa dallo scrittore è essa stessa un xref
        // stream: il resolver deve riconoscerlo rileggendo davvero il blocco
        // appena prodotto, non solo verificando sottostringhe nell'output.
        self::assertTrue($info->isStreamBased);
        self::assertFalse($info->hasObjectStreams);
        self::assertFalse($info->isEncrypted);
        // buildXrefStreamUpdate() alloca il nuovo oggetto xref con numero
        // = vecchio Size (3) e dichiara /Size = vecchio Size + 1 (4).
        self::assertSame(4, $info->size);
    }

    /**
     * Fixture reale (LibreOffice + pikepdf/qpdf) con xref stream genuina
     * (no object stream) e un tag firma iniettato in un content stream
     * FlateDecode reale: copre il gap "nessuna fixture PDF reale" segnalato
     * dalla review finale. Vedi src/Test/Unit/Model/Pdf/_fixtures/.
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

        // Contenuto tipico iniettato dal builder
        $streamContent = 'q BT /MDSHelv1 10 Tf 100 100 Td ({WSIGN#80,20#placeholder@example.com}) Tj ET Q';
        $pdf = $this->buildPdf($streamContent);

        // Un context non nullo rappresenta la generazione reale del documento per un
        // ordine (vedi DocumentProcessor): solo qui va applicato il colore configurato.
        $result = $this->tagReplacer->replaceSignerEmail($pdf, 'cliente.reale@example.com', $contextMock);

        // La stringa del content stream modificato deve contenere l'operatore di colore
        self::assertStringContainsString('q 1 0 0 rg BT /MDSHelv1', $result);
    }

    public function testReplaceSignerEmailKeepsTagVisibleWithoutContextForPreview(): void
    {
        $this->scopeConfigMock->expects(self::never())->method('getValue');

        // Stesso contenuto builder-injected della generazione reale, ma senza context:
        // rappresenta l'anteprima (Preview.php) e il dry-run di validazione
        // (TemplateValidator), che passano sempre null. Il tag deve restare
        // visibile per permettere al merchant di verificarne la posizione.
        $streamContent = 'q BT /MDSHelv1 10 Tf 100 100 Td ({WSIGN#80,20#placeholder@example.com}) Tj ET Q';
        $pdf = $this->buildPdf($streamContent);

        $result = $this->tagReplacer->replaceSignerEmail($pdf, 'cliente.reale@example.com');

        self::assertStringContainsString('q BT /MDSHelv1', $result);
        self::assertStringNotContainsString('rg BT /MDSHelv1', $result);
        self::assertStringNotContainsString('g BT /MDSHelv1', $result);
    }

    /**
     * PDF 1.4 minimo con un content stream e trailer classico. Gli offset
     * della xref sono fittizi: TagReplacer usa solo startxref e trailer.
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
