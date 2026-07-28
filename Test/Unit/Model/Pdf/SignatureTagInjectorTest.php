<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Pdf;

use MageOS\DigitalSignature\Model\Pdf\IncrementalUpdateWriter;
use MageOS\DigitalSignature\Model\Pdf\SignatureTagInjector;
use MageOS\DigitalSignature\Model\Pdf\TagReplacer;
use MageOS\DigitalSignature\Model\Pdf\Xref\ClassicXrefReader;
use MageOS\DigitalSignature\Model\Pdf\Xref\PageTreeResolver;
use MageOS\DigitalSignature\Model\Pdf\Xref\XrefChainResolver;
use MageOS\DigitalSignature\Model\Pdf\Xref\XrefStreamReader;
use MageOS\DigitalSignature\Test\Unit\Model\Pdf\Xref\PdfObjectFixtureTrait;
use PHPUnit\Framework\TestCase;

class SignatureTagInjectorTest extends TestCase
{
    use PdfObjectFixtureTrait;

    private SignatureTagInjector $injector;
    private TagReplacer $tagReplacer;

    protected function setUp(): void
    {
        $xrefResolver = new XrefChainResolver(new ClassicXrefReader(), new XrefStreamReader());
        $updateWriter = new IncrementalUpdateWriter($xrefResolver);
        $this->injector = new SignatureTagInjector(new PageTreeResolver($xrefResolver), $updateWriter);
        
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

    public function testInjectsFindableTagUsingExistingFont(): void
    {
        $pdf = $this->pdfWithExistingFont();

        $result = $this->injector->injectAt($pdf, 1, 72.0, 700.0, 80.0, 20.0);

        self::assertStringContainsString('%PDF', $result);
        $tags = $this->tagReplacer->findTags($result);
        self::assertCount(1, $tags);
        self::assertStringContainsString('{WSIGN#80,20#' . SignatureTagInjector::PLACEHOLDER_EMAIL, $tags[0]);
    }

    public function testInjectedTagUsesDedicatedFontEvenWhenExistingFontExists(): void
    {
        $pdf = $this->pdfWithExistingFont();

        $result = $this->injector->injectAt($pdf, 1, 72.0, 700.0, 80.0, 20.0);

        // A new Font object (MDSHelv1) is added to avoid subsetting issues:
        // (5 original objects + 1 content-stream + 1 Font object + 1 xref = 8).
        self::assertSame(8, preg_match_all('/\d+\s+0\s+obj/', $result));
        self::assertStringContainsString('/MDSHelv1', $result);
    }

    public function testInjectedTagSurvivesRealSignerEmailReplacement(): void
    {
        $pdf = $this->pdfWithExistingFont();

        $withTag = $this->injector->injectAt($pdf, 1, 72.0, 700.0, 80.0, 20.0);
        $withEmail = $this->tagReplacer->replaceSignerEmail($withTag, 'cliente.reale@example.com');

        self::assertSame(
            ['{WSIGN#80,20#cliente.reale@example.com}'],
            $this->tagReplacer->findTags($withEmail)
        );
    }

    public function testRejectsUnsupportedPageStructure(): void
    {
        $objects = [
            1 => "1 0 obj\n<</Type /Catalog /Pages 2 0 R>>\nendobj\n",
            2 => "2 0 obj\n<</Type /Pages /Kids [3 0 R] /Count 1>>\nendobj\n",
            3 => "3 0 obj\n<</Type /Page /Parent 2 0 R /Rotate 180 /Contents 4 0 R "
                . "/Resources <</Font <</F1 5 0 R>>>>>>\nendobj\n",
            4 => $this->contentStreamObject(4, 'BT ET'),
            5 => "5 0 obj\n<</Type /Font /Subtype /Type1 /BaseFont /Helvetica>>\nendobj\n",
        ];
        $pdf = $this->buildClassicXrefPdf($objects, 1);

        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);

        $this->injector->injectAt($pdf, 1, 72.0, 700.0, 80.0, 20.0);
    }

    private function pdfWithoutFont(): string
    {
        $objects = [
            1 => "1 0 obj\n<</Type /Catalog /Pages 2 0 R>>\nendobj\n",
            2 => "2 0 obj\n<</Type /Pages /Kids [3 0 R] /Count 1>>\nendobj\n",
            3 => "3 0 obj\n<</Type /Page /Parent 2 0 R /Contents 4 0 R "
                . "/Resources <</ProcSet [/PDF]>>>>\nendobj\n",
            4 => $this->contentStreamObject(4, 'q 1 0 0 RG 0 0 100 100 re S Q'),
        ];

        return $this->buildClassicXrefPdf($objects, 1);
    }

    public function testInjectsFindableTagAddingNewFontWhenNoneExists(): void
    {
        $pdf = $this->pdfWithoutFont();

        $result = $this->injector->injectAt($pdf, 1, 50.0, 400.0, 60.0, 15.0);

        $tags = $this->tagReplacer->findTags($result);
        self::assertCount(1, $tags);
        self::assertStringContainsString('{WSIGN#60,15#' . SignatureTagInjector::PLACEHOLDER_EMAIL, $tags[0]);
        self::assertStringContainsString('/Type /Font', $result);
        self::assertStringContainsString('/BaseFont /Helvetica', $result);
    }

    public function testAddedFontIsReferencedInRewrittenPageResources(): void
    {
        $pdf = $this->pdfWithoutFont();

        $result = $this->injector->injectAt($pdf, 1, 50.0, 400.0, 60.0, 15.0);

        self::assertStringContainsString('/Font <</MDSHelv1', $result);
    }

    public function testInjectedTagWithNewFontSurvivesRealSignerEmailReplacement(): void
    {
        $pdf = $this->pdfWithoutFont();

        $withTag = $this->injector->injectAt($pdf, 1, 50.0, 400.0, 60.0, 15.0);
        $withEmail = $this->tagReplacer->replaceSignerEmail($withTag, 'cliente.reale@example.com');

        self::assertSame(
            ['{WSIGN#60,15#cliente.reale@example.com}'],
            $this->tagReplacer->findTags($withEmail)
        );
    }

    private function pdfWithIndirectResources(): string
    {
        $objects = [
            1 => "1 0 obj\n<</Type /Catalog /Pages 2 0 R>>\nendobj\n",
            2 => "2 0 obj\n<</Type /Pages /Kids [3 0 R] /Count 1>>\nendobj\n",
            3 => "3 0 obj\n<</Type /Page /Parent 2 0 R /Contents 4 0 R /Resources 5 0 R>>\nendobj\n",
            4 => $this->contentStreamObject(4, 'q 1 0 0 RG 0 0 100 100 re S Q'),
            5 => "5 0 obj\n<</ProcSet [/PDF]>>\nendobj\n",
        ];

        return $this->buildClassicXrefPdf($objects, 1);
    }

    public function testInjectsFindableTagWhenResourcesAreIndirect(): void
    {
        $pdf = $this->pdfWithIndirectResources();

        $result = $this->injector->injectAt($pdf, 1, 50.0, 400.0, 60.0, 15.0);

        $tags = $this->tagReplacer->findTags($result);
        self::assertCount(1, $tags);
        self::assertStringContainsString('{WSIGN#60,15#' . SignatureTagInjector::PLACEHOLDER_EMAIL, $tags[0]);
    }

    public function testIndirectResourcesObjectIsRewrittenWithNewFontNotThePageDict(): void
    {
        $pdf = $this->pdfWithIndirectResources();

        $result = $this->injector->injectAt($pdf, 1, 50.0, 400.0, 60.0, 15.0);

        // The font must be added in the rewritten /Resources object (5 0 obj),
        // not injected inline into the Page object's dictionary (3 0 obj).
        self::assertMatchesRegularExpression(
            '/5 0 obj\s*<<[^>]*\/Font\s*<<\/MDSHelv1/',
            $result
        );
        self::assertDoesNotMatchRegularExpression(
            '/3 0 obj\s*<<[^>]*\/Font/',
            $result
        );
    }

    public function testIndirectResourcesTagSurvivesRealSignerEmailReplacement(): void
    {
        $pdf = $this->pdfWithIndirectResources();

        $withTag = $this->injector->injectAt($pdf, 1, 50.0, 400.0, 60.0, 15.0);
        $withEmail = $this->tagReplacer->replaceSignerEmail($withTag, 'cliente.reale@example.com');

        self::assertSame(
            ['{WSIGN#60,15#cliente.reale@example.com}'],
            $this->tagReplacer->findTags($withEmail)
        );
    }
}
