<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Pdf\Xref;

/**
 * Builds a PDF with a classic xref from a list of already-ready objects
 * (full text "N G obj ... endobj"), used by tests that need to
 * build a real page tree (Catalog/Pages/Page/Contents) without having to
 * compute offsets by hand.
 */
trait PdfObjectFixtureTrait
{
    /**
     * @param array<int, string> $objects object number => full text of the object
     */
    private function buildClassicXrefPdf(array $objects, int $rootNumber): string
    {
        $body = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $number => $text) {
            $offsets[$number] = strlen($body);
            $body .= $text;
        }

        $size = max(array_keys($offsets)) + 1;
        $xrefPos = strlen($body);
        $xref = "xref\n0 {$size}\n";
        for ($n = 0; $n < $size; $n++) {
            if ($n === 0) {
                $xref .= "0000000000 65535 f \n";
                continue;
            }
            $offset = $offsets[$n] ?? 0;
            $xref .= sprintf("%010d 00000 n \n", $offset);
        }
        $trailer = sprintf(
            "trailer\n<</Size %d /Root %d 0 R>>\nstartxref\n%d\n%%%%EOF\n",
            $size,
            $rootNumber,
            $xrefPos
        );

        return $body . $xref . $trailer;
    }
}
