<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Pdf;

use MageOS\DigitalSignature\Model\Pdf\IncrementalUpdateWriter;
use MageOS\DigitalSignature\Model\Pdf\SignatureStamp;
use MageOS\DigitalSignature\Model\Pdf\SignatureTagInjector;
use MageOS\DigitalSignature\Model\Pdf\TagReplacer;
use MageOS\DigitalSignature\Model\Pdf\Xref\ClassicXrefReader;
use MageOS\DigitalSignature\Model\Pdf\Xref\PageTreeResolver;
use MageOS\DigitalSignature\Model\Pdf\Xref\XrefChainResolver;
use MageOS\DigitalSignature\Model\Pdf\Xref\XrefStreamReader;
use MageOS\DigitalSignature\Test\Unit\Model\Pdf\Xref\PdfObjectFixtureTrait;
use PHPUnit\Framework\TestCase;

class SignatureStampTest extends TestCase
{
    use PdfObjectFixtureTrait;

    private const STAMP_TEXT = 'DUMMY SIGNED at 12:01:00';

    private SignatureTagInjector $injector;
    private TagReplacer $tagReplacer;
    private SignatureStamp $stamp;

    protected function setUp(): void
    {
        $xrefResolver = new XrefChainResolver(new ClassicXrefReader(), new XrefStreamReader());
        $updateWriter = new IncrementalUpdateWriter($xrefResolver);
        $pageResolver = new PageTreeResolver($xrefResolver);

        $this->injector = new SignatureTagInjector($pageResolver, $updateWriter);
        $mergeFieldPoolMock = $this->createMock(\MageOS\DigitalSignature\Api\MergeFieldPoolInterface::class);
        $previewOrderContextMock = $this->createMock(\MageOS\DigitalSignature\Model\Pdf\PreviewOrderContext::class);
        $scopeConfigMock = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $this->tagReplacer = new TagReplacer(
            $xrefResolver,
            $updateWriter,
            $mergeFieldPoolMock,
            $previewOrderContextMock,
            $scopeConfigMock
        );
        $this->stamp = new SignatureStamp($pageResolver, $updateWriter);
    }

    private function contentStreamObject(int $number, string $text): string
    {
        return "{$number} 0 obj\n<</Length " . strlen($text) . ">>\nstream\n{$text}\nendstream\nendobj\n";
    }

    private function pdfWithExistingFont(): string
    {
        $objects = [
            1 => "1 0 obj\n<</Type /Catalog /Pages 2 0 R>>\nendobj\n",
            2 => "2 0 obj\n<</Type /Pages /Kids [3 0 R] /Count 1>>\nendobj\n",
            3 => "3 0 obj\n<</Type /Page /Parent 2 0 R /Contents 4 0 R "
                . "/Resources <</Font <</F1 5 0 R>>>>>>\nendobj\n",
            4 => $this->contentStreamObject(4, 'BT /F1 12 Tf (documento) Tj ET'),
            5 => "5 0 obj\n<</Type /Font /Subtype /Type1 /BaseFont /Helvetica>>\nendobj\n",
        ];

        return $this->buildClassicXrefPdf($objects, 1);
    }

    /**
     * Builds a "ready to sign" document exactly as the real pipeline
     * produces it: a template gets the tag injected at build time
     * (/Contents becomes an array), then the signer's real email is merged
     * in at order time. This is the shape SignatureStamp must handle.
     */
    private function readyToSignPdf(float $x, float $y, float $widthMm, float $heightMm): string
    {
        $withTag = $this->injector->injectAt($this->pdfWithExistingFont(), 1, $x, $y, $widthMm, $heightMm);

        return $this->tagReplacer->replaceSignerEmail($withTag, 'cliente.reale@example.com');
    }

    public function testDrawsBoxAndTextAtTheTagCoordinatesReusingTheExistingFont(): void
    {
        $pdf = $this->readyToSignPdf(72.0, 700.0, 80.0, 20.0);

        $result = $this->stamp->stampAt($pdf, self::STAMP_TEXT);

        // Box: black stroke, same origin/size as the tag (80mm/20mm -> pt).
        self::assertMatchesRegularExpression(
            '/q 0 0 0 RG 1 w 72 700 226\.77 56\.69 re S Q/',
            $result
        );
        // Text drawn with the SAME font the tag itself used (MDSHelv1,
        // added by SignatureTagInjector): no new font object is created.
        self::assertMatchesRegularExpression(
            '/BT \/MDSHelv1 8 Tf 74 702 Td \(DUMMY SIGNED at 12:01:00\) Tj ET/',
            $result
        );
        self::assertStringNotContainsString('MDSStamp1', $result);
    }

    public function testAddsItsOwnFontWhenPageHasNoneYet(): void
    {
        $objects = [
            1 => "1 0 obj\n<</Type /Catalog /Pages 2 0 R>>\nendobj\n",
            2 => "2 0 obj\n<</Type /Pages /Kids [3 0 R] /Count 1>>\nendobj\n",
            3 => "3 0 obj\n<</Type /Page /Parent 2 0 R /Contents 4 0 R "
                . "/Resources <</ProcSet [/PDF]>>>>\nendobj\n",
            4 => $this->contentStreamObject(4, 'q 1 0 0 RG 0 0 100 100 re S Q'),
        ];
        $pdf = $this->buildClassicXrefPdf($objects, 1);
        $withTag = $this->injector->injectAt($pdf, 1, 50.0, 400.0, 60.0, 15.0);
        $signed = $this->tagReplacer->replaceSignerEmail($withTag, 'cliente.reale@example.com');

        $result = $this->stamp->stampAt($signed, self::STAMP_TEXT);

        // SignatureTagInjector already added its own dedicated font (MDSHelv1):
        // the stamp must reuse it too, never add a second one of its own.
        self::assertStringNotContainsString('MDSStamp1', $result);
        self::assertStringContainsString('/MDSHelv1', $result);
        self::assertStringContainsString(self::STAMP_TEXT, $result);
    }

    public function testReturnsPdfUnchangedWhenNoSignatureTagIsPresent(): void
    {
        $pdf = $this->pdfWithExistingFont();

        $result = $this->stamp->stampAt($pdf, self::STAMP_TEXT);

        self::assertSame($pdf, $result);
    }

    public function testWorksWithIndirectResources(): void
    {
        $objects = [
            1 => "1 0 obj\n<</Type /Catalog /Pages 2 0 R>>\nendobj\n",
            2 => "2 0 obj\n<</Type /Pages /Kids [3 0 R] /Count 1>>\nendobj\n",
            3 => "3 0 obj\n<</Type /Page /Parent 2 0 R /Contents 4 0 R /Resources 5 0 R>>\nendobj\n",
            4 => $this->contentStreamObject(4, 'q 1 0 0 RG 0 0 100 100 re S Q'),
            5 => "5 0 obj\n<</ProcSet [/PDF]>>\nendobj\n",
        ];
        $pdf = $this->buildClassicXrefPdf($objects, 1);
        $withTag = $this->injector->injectAt($pdf, 1, 50.0, 400.0, 60.0, 15.0);
        $signed = $this->tagReplacer->replaceSignerEmail($withTag, 'cliente.reale@example.com');

        $result = $this->stamp->stampAt($signed, self::STAMP_TEXT);

        self::assertStringContainsString(self::STAMP_TEXT, $result);
        self::assertStringContainsString('re S Q', $result);
    }

    public function testEscapesParenthesesInTheStampText(): void
    {
        $pdf = $this->readyToSignPdf(72.0, 700.0, 80.0, 20.0);

        $result = $this->stamp->stampAt($pdf, 'DUMMY SIGNED (demo)');

        self::assertStringContainsString('DUMMY SIGNED \\(demo\\)', $result);
    }
}
