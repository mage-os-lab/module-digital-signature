<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Pdf;

use MageOS\DigitalSignature\Model\Pdf\Xref\StartxrefLocator;
use MageOS\DigitalSignature\Model\Pdf\Xref\XrefChainResolver;
use MageOS\DigitalSignature\Model\Pdf\Xref\XrefInfo;
use Magento\Framework\Exception\LocalizedException;

/**
 * Writes a PDF "incremental update": appends new/modified objects at the end
 * of the file and a new cross-reference section (classic table or xref
 * stream, according to the format of the most recent revision of the
 * source), linked to the previous one via /Prev. Shared between TagReplacer
 * (replacement of existing objects) and SignatureTagInjector (introduction
 * of objects never used before, e.g. a font).
 */
class IncrementalUpdateWriter
{
    public function __construct(private readonly XrefChainResolver $xrefResolver)
    {
    }

    public function buildStreamObject(int $number, string $content, bool $compress): string
    {
        $stream = $compress ? gzcompress($content, 9) : $content;
        $dict = $compress
            ? sprintf('<</Filter /FlateDecode /Length %d>>', strlen($stream))
            : sprintf('<</Length %d>>', strlen($stream));

        return sprintf("%d 0 obj\n%s\nstream\n%s\nendstream\nendobj\n", $number, $dict, $stream);
    }

    /**
     * Appends the modified/new objects with a new xref section linked to the
     * previous one (/Prev), following the incremental update mechanism. The
     * format of the new section (classic table or xref stream) follows that
     * of the most recent revision of $originalPdf.
     *
     * @param array<int, array{number: int, body: string}> $modifiedObjects existing
     *        object numbers (rewritten) or never used before (new, e.g. a font)
     * @throws LocalizedException
     */
    public function append(string $pdf, string $originalPdf, array $modifiedObjects): string
    {
        $prevXref = StartxrefLocator::locate($originalPdf);
        $info = $this->xrefResolver->resolve($originalPdf);

        $output = rtrim($pdf, "\n\r") . "\n";
        $offsets = [];
        foreach ($modifiedObjects as $object) {
            $offsets[$object['number']] = strlen($output);
            $output .= $object['body'];
        }

        // Next free object number: beyond the /Size originally declared, or
        // beyond the highest number already used in $modifiedObjects (a new
        // object, e.g. a font, may already occupy exactly $info->size).
        $usedNumbers = array_map(static fn (array $o): int => $o['number'] + 1, $modifiedObjects);
        $nextFreeNumber = max(array_merge([$info->size], $usedNumbers));

        if ($info->isStreamBased) {
            return $output . $this->buildXrefStreamUpdate($output, $offsets, $info, $prevXref, $nextFreeNumber);
        }

        ksort($offsets);
        $xrefOffset = strlen($output);
        $xref = "xref\n";
        foreach ($offsets as $number => $offset) {
            $xref .= sprintf("%d 1\n%010d 00000 n \n", $number, $offset);
        }
        $newTrailer = sprintf(
            "trailer\n<</Size %d /Root %s /Prev %d>>\nstartxref\n%d\n%%%%EOF\n",
            $nextFreeNumber,
            $info->root,
            $prevXref,
            $xrefOffset
        );

        return $output . $xref . $newTrailer;
    }

    /**
     * @param array<int, int> $offsets object number => offset in the file
     */
    private function buildXrefStreamUpdate(
        string $outputSoFar,
        array $offsets,
        XrefInfo $info,
        int $prevXref,
        int $nextFreeNumber
    ): string {
        $xrefObjNum = $nextFreeNumber;
        $xrefOffset = strlen($outputSoFar);
        $offsets[$xrefObjNum] = $xrefOffset;
        ksort($offsets);

        $index = [];
        $rows = '';
        foreach ($offsets as $number => $offset) {
            $index[] = $number;
            $index[] = 1;
            $rows .= chr(1) . $this->encodeUint($offset, 4) . $this->encodeUint(0, 2);
        }

        $dict = sprintf(
            '<</Type /XRef /Size %d /W [1 4 2] /Index [%s] /Root %s /Prev %d /Length %d>>',
            $xrefObjNum + 1,
            implode(' ', $index),
            $info->root,
            $prevXref,
            strlen($rows)
        );

        return sprintf(
            "%d 0 obj\n%s\nstream\n%s\nendstream\nendobj\nstartxref\n%d\n%%%%EOF\n",
            $xrefObjNum,
            $dict,
            $rows,
            $xrefOffset
        );
    }

    private function encodeUint(int $value, int $width): string
    {
        $bytes = '';
        for ($i = $width - 1; $i >= 0; $i--) {
            $bytes .= chr(($value >> ($i * 8)) & 0xFF);
        }

        return $bytes;
    }
}
