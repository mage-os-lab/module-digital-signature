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
        $this->expectExceptionMessageMatches('/maximum size/');

        $this->validator->validate(str_repeat('a', 10485761));
    }

    public function testRejectsNonPdfContent(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/not a valid PDF/');

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
        // Dynamically computed xref offset: a static value (as it was in the
        // old fixture) risks getting misaligned from the real position of the
        // "xref" keyword and making the resolver fail for a reason different
        // from the one the test is meant to exercise.
        $header = "%PDF-1.4\ncontenuto\n";
        $xrefOffset = strlen($header);
        $pdf = $header . "xref\n0 1\n0000000000 65535 f \ntrailer\n<</Size 1 /Root 1 0 R>>\nstartxref\n{$xrefOffset}\n%%EOF\n";

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/No signature tag found/');

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

        // row 1: object 1 declared as compressed in an object stream (type 2);
        // row 2: the xref stream object itself. The validator must reject
        // regardless of whether a container actually exists.
        $rows = $this->buildXrefStreamRows([[2, 5, 0], [1, $xrefOffset, 0]], 1, 4, 2);
        $xrefObj = "2 0 obj\n<</Type /XRef /Size 3 /Root 1 0 R /W [1 4 2] /Index [1 2]"
            . ' /Length ' . strlen($rows) . ">>\nstream\n{$rows}\nendstream\nendobj\n";

        $pdf = $header . $obj1 . $xrefObj . "startxref\n{$xrefOffset}\n%%EOF\n";

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/object stream/');

        $this->validator->validate($pdf);
    }

    /**
     * Real fixtures (produced by LibreOffice + pikepdf/qpdf, not hand-built
     * byte-by-byte) with a signature tag injected into a genuine FlateDecode
     * content stream: they cover the "no real PDF fixture" gap flagged
     * by the branch's final review. See src/Test/Unit/Model/Pdf/_fixtures/.
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
        $this->expectExceptionMessageMatches('/[Ee]ncrypted/');

        $this->validator->validate($pdf);
    }
}
