<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Pdf;

use MageOS\DigitalSignature\Model\Pdf\IncrementalUpdateWriter;
use MageOS\DigitalSignature\Model\Pdf\TagReplacer;
use MageOS\DigitalSignature\Model\Pdf\TemplateValidator;
use MageOS\DigitalSignature\Model\Pdf\Xref\ClassicXrefReader;
use MageOS\DigitalSignature\Model\Pdf\Xref\XrefChainResolver;
use MageOS\DigitalSignature\Model\Pdf\Xref\XrefStreamReader;
use MageOS\DigitalSignature\Test\Unit\Model\Pdf\Xref\XrefStreamFixtureTrait;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class TemplateValidatorTest extends TestCase
{
    use XrefStreamFixtureTrait;

    private TemplateValidator $validator;

    protected function setUp(): void
    {
        $xrefResolver = new XrefChainResolver(new ClassicXrefReader(), new XrefStreamReader());
        $mergeFieldPoolMock = $this->createMock(\MageOS\DigitalSignature\Api\MergeFieldPoolInterface::class);
        $previewOrderContextMock = $this->createMock(\MageOS\DigitalSignature\Model\Pdf\PreviewOrderContext::class);
        $scopeConfigMock = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $tagReplacer = new TagReplacer(
            $xrefResolver,
            new IncrementalUpdateWriter($xrefResolver),
            $mergeFieldPoolMock,
            $previewOrderContextMock,
            $scopeConfigMock
        );
        $this->validator = new TemplateValidator($tagReplacer, $xrefResolver);
    }

    public function testRejectsOversizedFile(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/dimensione massima/');

        $this->validator->validate(str_repeat('a', 10485761));
    }

    public function testRejectsNonPdfContent(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/non è un PDF/');

        $this->validator->validate('<html>non pdf</html>');
    }

    public function testRejectsPdfWithoutAnyXref(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/startxref/');

        $this->validator->validate("%PDF-1.7\ncontenuto {WSIGN#80,20#a@b.it} senza xref");
    }

    public function testRejectsPdfWithoutSignatureTag(): void
    {
        // Offset di xref calcolato dinamicamente: un valore statico (com'era nel
        // vecchio fixture) rischia di disallinearsi dalla reale posizione della
        // keyword "xref" e far fallire il resolver per un motivo diverso da
        // quello che il test intende esercitare.
        $header = "%PDF-1.4\ncontenuto\n";
        $xrefOffset = strlen($header);
        $pdf = $header . "xref\n0 1\n0000000000 65535 f \ntrailer\n<</Size 1 /Root 1 0 R>>\nstartxref\n{$xrefOffset}\n%%EOF\n";

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/Nessun tag firma/');

        $this->validator->validate($pdf);
    }

    public function testAcceptsValidClassicTemplate(): void
    {
        $this->expectNotToPerformAssertions();

        $content = '{WSIGN#80,20#firmatario@example.com}';
        $pdf = "%PDF-1.4\n1 0 obj\n<</Length " . strlen($content) . ">>\nstream\n{$content}\nendstream\nendobj\n";
        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 2\n0000000000 65535 f \n0000000000 00000 n \n";
        $pdf .= "trailer\n<</Size 2 /Root 1 0 R>>\nstartxref\n{$xrefPos}\n%%EOF\n";

        $this->validator->validate($pdf);
    }

    public function testAcceptsValidXrefStreamTemplate(): void
    {
        $this->expectNotToPerformAssertions();

        $header = "%PDF-1.7\n";
        $obj1Offset = strlen($header);
        $content = '{WSIGN#80,20#firmatario@example.com}';
        $obj1 = "1 0 obj\n<</Length " . strlen($content) . ">>\nstream\n{$content}\nendstream\nendobj\n";
        $xrefOffset = strlen($header . $obj1);

        $rows = $this->buildXrefStreamRows([[1, $obj1Offset, 0], [1, $xrefOffset, 0]], 1, 4, 2);
        $xrefObj = "2 0 obj\n<</Type /XRef /Size 3 /Root 1 0 R /W [1 4 2] /Index [1 2]"
            . ' /Length ' . strlen($rows) . ">>\nstream\n{$rows}\nendstream\nendobj\n";

        $pdf = $header . $obj1 . $xrefObj . "startxref\n{$xrefOffset}\n%%EOF\n";

        $this->validator->validate($pdf);
    }

    public function testRejectsPdfWithObjectStreamEntries(): void
    {
        $header = "%PDF-1.7\n";
        $content = '{WSIGN#80,20#firmatario@example.com}';
        $obj1 = "1 0 obj\n<</Length " . strlen($content) . ">>\nstream\n{$content}\nendstream\nendobj\n";
        $xrefOffset = strlen($header . $obj1);

        // riga 1: oggetto 1 dichiarato compresso in un object stream (tipo 2);
        // riga 2: l'oggetto xref stream stesso. Il validator deve rifiutare
        // a prescindere dall'esistenza reale di un container.
        $rows = $this->buildXrefStreamRows([[2, 5, 0], [1, $xrefOffset, 0]], 1, 4, 2);
        $xrefObj = "2 0 obj\n<</Type /XRef /Size 3 /Root 1 0 R /W [1 4 2] /Index [1 2]"
            . ' /Length ' . strlen($rows) . ">>\nstream\n{$rows}\nendstream\nendobj\n";

        $pdf = $header . $obj1 . $xrefObj . "startxref\n{$xrefOffset}\n%%EOF\n";

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/object stream/');

        $this->validator->validate($pdf);
    }

    /**
     * Fixture reali (prodotte da LibreOffice + pikepdf/qpdf, non costruite a
     * mano byte-per-byte) con un tag firma iniettato in un content stream
     * FlateDecode genuino: coprono il gap "nessuna fixture PDF reale" segnalato
     * dalla review finale del branch. Vedi src/Test/Unit/Model/Pdf/_fixtures/.
     */
    public function testAcceptsRealLibreOfficeClassicXrefTemplate(): void
    {
        $this->expectNotToPerformAssertions();

        $pdf = file_get_contents(__DIR__ . '/_fixtures/real-classic-xref.pdf');
        $this->validator->validate($pdf);
    }

    public function testAcceptsRealXrefStreamTemplate(): void
    {
        $this->expectNotToPerformAssertions();

        $pdf = file_get_contents(__DIR__ . '/_fixtures/real-xref-stream.pdf');
        $this->validator->validate($pdf);
    }

    public function testRejectsRealPdfWithObjectStreams(): void
    {
        $pdf = file_get_contents(__DIR__ . '/_fixtures/real-xref-stream-objstm.pdf');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/object stream/');

        $this->validator->validate($pdf);
    }

    public function testRejectsEncryptedPdf(): void
    {
        $header = "%PDF-1.7\n";
        $obj1Offset = strlen($header);
        $content = '{WSIGN#80,20#firmatario@example.com}';
        $obj1 = "1 0 obj\n<</Length " . strlen($content) . ">>\nstream\n{$content}\nendstream\nendobj\n";
        $xrefOffset = strlen($header . $obj1);

        $rows = $this->buildXrefStreamRows([[1, $obj1Offset, 0], [1, $xrefOffset, 0]], 1, 4, 2);
        $xrefObj = "2 0 obj\n<</Type /XRef /Size 3 /Root 1 0 R /Encrypt 9 0 R /W [1 4 2] /Index [1 2]"
            . ' /Length ' . strlen($rows) . ">>\nstream\n{$rows}\nendstream\nendobj\n";

        $pdf = $header . $obj1 . $xrefObj . "startxref\n{$xrefOffset}\n%%EOF\n";

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/cifrati/');

        $this->validator->validate($pdf);
    }
}
