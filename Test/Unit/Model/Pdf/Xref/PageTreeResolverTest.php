<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Pdf\Xref;

use MageOS\DigitalSignature\Model\Pdf\Xref\ClassicXrefReader;
use MageOS\DigitalSignature\Model\Pdf\Xref\PageTreeResolver;
use MageOS\DigitalSignature\Model\Pdf\Xref\XrefChainResolver;
use MageOS\DigitalSignature\Model\Pdf\Xref\XrefStreamReader;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class PageTreeResolverTest extends TestCase
{
    use PdfObjectFixtureTrait;

    private PageTreeResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new PageTreeResolver(
            new XrefChainResolver(new ClassicXrefReader(), new XrefStreamReader())
        );
    }

    private function contentStreamObject(int $number, string $text): string
    {
        return "{$number} 0 obj\n<</Length " . strlen($text) . ">>\nstream\n{$text}\nendstream\nendobj\n";
    }

    public function testResolvesSinglePageWithExistingFont(): void
    {
        $objects = [
            1 => "1 0 obj\n<</Type /Catalog /Pages 2 0 R>>\nendobj\n",
            2 => "2 0 obj\n<</Type /Pages /Kids [3 0 R] /Count 1>>\nendobj\n",
            3 => "3 0 obj\n<</Type /Page /Parent 2 0 R /Contents 4 0 R "
                . "/Resources <</Font <</F1 5 0 R>> /ProcSet [/PDF /Text]>>>>\nendobj\n",
            4 => $this->contentStreamObject(4, 'BT ET'),
            5 => "5 0 obj\n<</Type /Font /Subtype /Type1 /BaseFont /Helvetica>>\nendobj\n",
        ];
        $pdf = $this->buildClassicXrefPdf($objects, 1);

        $page = $this->resolver->resolve($pdf, 1);

        self::assertSame(3, $page->pageObjectNumber);
        self::assertSame(4, $page->contentObjectNumber);
        self::assertSame('F1', $page->existingFontResourceName);
        self::assertSame(6, $page->xrefSize);
    }

    public function testResolvesSecondOfMultiplePages(): void
    {
        $objects = [
            1 => "1 0 obj\n<</Type /Catalog /Pages 2 0 R>>\nendobj\n",
            2 => "2 0 obj\n<</Type /Pages /Kids [3 0 R 4 0 R] /Count 2>>\nendobj\n",
            3 => "3 0 obj\n<</Type /Page /Parent 2 0 R /Contents 5 0 R "
                . "/Resources <</ProcSet [/PDF]>>>>\nendobj\n",
            4 => "4 0 obj\n<</Type /Page /Parent 2 0 R /Contents 6 0 R "
                . "/Resources <</ProcSet [/PDF]>>>>\nendobj\n",
            5 => $this->contentStreamObject(5, 'page one'),
            6 => $this->contentStreamObject(6, 'page two'),
        ];
        $pdf = $this->buildClassicXrefPdf($objects, 1);

        $page = $this->resolver->resolve($pdf, 2);

        self::assertSame(4, $page->pageObjectNumber);
        self::assertSame(6, $page->contentObjectNumber);
        self::assertNull($page->existingFontResourceName);
    }

    public function testRejectsPageNumberOutOfRange(): void
    {
        $objects = [
            1 => "1 0 obj\n<</Type /Catalog /Pages 2 0 R>>\nendobj\n",
            2 => "2 0 obj\n<</Type /Pages /Kids [3 0 R] /Count 1>>\nendobj\n",
            3 => "3 0 obj\n<</Type /Page /Parent 2 0 R /Contents 4 0 R /Resources <<>>>>\nendobj\n",
            4 => $this->contentStreamObject(4, 'x'),
        ];
        $pdf = $this->buildClassicXrefPdf($objects, 1);

        $this->expectException(LocalizedException::class);

        $this->resolver->resolve($pdf, 2);
    }

    public function testRejectsNestedPagesTree(): void
    {
        $objects = [
            1 => "1 0 obj\n<</Type /Catalog /Pages 2 0 R>>\nendobj\n",
            2 => "2 0 obj\n<</Type /Pages /Kids [3 0 R] /Count 1>>\nendobj\n",
            // Il kid è a sua volta un nodo /Pages, non una /Page diretta.
            3 => "3 0 obj\n<</Type /Pages /Kids [4 0 R] /Count 1>>\nendobj\n",
            4 => "4 0 obj\n<</Type /Page /Parent 3 0 R /Contents 5 0 R /Resources <<>>>>\nendobj\n",
            5 => $this->contentStreamObject(5, 'x'),
        ];
        $pdf = $this->buildClassicXrefPdf($objects, 1);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/annidat/');

        $this->resolver->resolve($pdf, 1);
    }

    public function testRejectsRotatedPage(): void
    {
        $objects = [
            1 => "1 0 obj\n<</Type /Catalog /Pages 2 0 R>>\nendobj\n",
            2 => "2 0 obj\n<</Type /Pages /Kids [3 0 R] /Count 1>>\nendobj\n",
            3 => "3 0 obj\n<</Type /Page /Parent 2 0 R /Rotate 90 /Contents 4 0 R "
                . "/Resources <<>>>>\nendobj\n",
            4 => $this->contentStreamObject(4, 'x'),
        ];
        $pdf = $this->buildClassicXrefPdf($objects, 1);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/ruotata/');

        $this->resolver->resolve($pdf, 1);
    }

    public function testResolvesIndirectResources(): void
    {
        $objects = [
            1 => "1 0 obj\n<</Type /Catalog /Pages 2 0 R>>\nendobj\n",
            2 => "2 0 obj\n<</Type /Pages /Kids [3 0 R] /Count 1>>\nendobj\n",
            3 => "3 0 obj\n<</Type /Page /Parent 2 0 R /Contents 4 0 R /Resources 5 0 R>>\nendobj\n",
            4 => $this->contentStreamObject(4, 'x'),
            5 => "5 0 obj\n<</ProcSet [/PDF]>>\nendobj\n",
        ];
        $pdf = $this->buildClassicXrefPdf($objects, 1);

        $page = $this->resolver->resolve($pdf, 1);

        self::assertSame(3, $page->pageObjectNumber);
        self::assertSame(5, $page->resourcesObjectNumber);
        self::assertStringContainsString('/ProcSet', $page->resourcesDictText);
        self::assertNull($page->existingFontResourceName);
    }

    public function testResolvesIndirectResourcesWithExistingFont(): void
    {
        $objects = [
            1 => "1 0 obj\n<</Type /Catalog /Pages 2 0 R>>\nendobj\n",
            2 => "2 0 obj\n<</Type /Pages /Kids [3 0 R] /Count 1>>\nendobj\n",
            3 => "3 0 obj\n<</Type /Page /Parent 2 0 R /Contents 4 0 R /Resources 5 0 R>>\nendobj\n",
            4 => $this->contentStreamObject(4, 'x'),
            5 => "5 0 obj\n<</Font <</F1 6 0 R>> /ProcSet [/PDF]>>\nendobj\n",
            6 => "6 0 obj\n<</Type /Font /Subtype /Type1 /BaseFont /Helvetica>>\nendobj\n",
        ];
        $pdf = $this->buildClassicXrefPdf($objects, 1);

        $page = $this->resolver->resolve($pdf, 1);

        self::assertSame(5, $page->resourcesObjectNumber);
        self::assertSame('F1', $page->existingFontResourceName);
    }

    public function testRejectsMissingResources(): void
    {
        $objects = [
            1 => "1 0 obj\n<</Type /Catalog /Pages 2 0 R>>\nendobj\n",
            2 => "2 0 obj\n<</Type /Pages /Kids [3 0 R] /Count 1>>\nendobj\n",
            3 => "3 0 obj\n<</Type /Page /Parent 2 0 R /Contents 4 0 R>>\nendobj\n",
            4 => $this->contentStreamObject(4, 'x'),
        ];
        $pdf = $this->buildClassicXrefPdf($objects, 1);

        $this->expectException(LocalizedException::class);

        $this->resolver->resolve($pdf, 1);
    }
}
