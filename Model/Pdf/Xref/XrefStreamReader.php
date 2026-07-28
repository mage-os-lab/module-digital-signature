<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Pdf\Xref;

use Magento\Framework\Exception\LocalizedException;

/**
 * Reads a cross-reference stream object (PDF 1.5+, /Type /XRef): decodes
 * the dictionary, decompresses the stream (FlateDecode + optional PNG
 * predictor) and interprets the binary rows according to /W and /Index.
 * Supports the Colors=1/BitsPerComponent=8 case (by far the most common for
 * xref streams) and predictor 1 (none) or 10-15 (generic PNG: each row
 * carries its own filter-type byte, read and applied individually).
 */
final class XrefStreamReader
{
    /**
     * Cap on the decompressed size of the stream, same anti
     * "decompression bomb" defense already used in TagReplacer.
     */
    private const MAX_DECOMPRESSED_STREAM = 52428800;

    /**
     * @throws LocalizedException
     */
    public function readAt(string $pdf, int $offset): XrefLink
    {
        $region = substr($pdf, $offset);
        if (!preg_match('/^(\d+)\s+(\d+)\s+obj\s*<</', $region, $header)) {
            throw new LocalizedException(__('Unsupported PDF: cross-reference stream object not recognized.'));
        }
        if (!preg_match('/>>\s*stream(\r\n|\n)/', $region, $streamMatch, PREG_OFFSET_CAPTURE)) {
            throw new LocalizedException(__('Unsupported PDF: cross-reference stream not recognized.'));
        }
        $dictStart = strlen($header[0]);
        $dictEnd = $streamMatch[0][1];
        $dict = substr($region, $dictStart, $dictEnd - $dictStart);

        $dataStart = $streamMatch[0][1] + strlen($streamMatch[0][0]);
        $endstreamPos = strpos($region, 'endstream', $dataStart);
        if ($endstreamPos === false) {
            throw new LocalizedException(__('Unsupported PDF: incomplete cross-reference stream.'));
        }
        $dataEnd = $endstreamPos;
        if (substr($region, $dataEnd - 1, 1) === "\n") {
            $dataEnd--;
            if (substr($region, $dataEnd - 1, 1) === "\r") {
                $dataEnd--;
            }
        }
        $raw = substr($region, $dataStart, $dataEnd - $dataStart);

        if (!str_contains($dict, '/XRef')) {
            throw new LocalizedException(__('Unsupported PDF: expected an object of /Type /XRef.'));
        }

        $size = DictFields::extractInt($dict, 'Size');
        $root = DictFields::extractRef($dict, 'Root');
        $widths = DictFields::extractIntArray($dict, 'W');
        if ($size === null || $root === null || $widths === null || count($widths) !== 3) {
            throw new LocalizedException(__('Unsupported PDF: incomplete cross-reference stream dictionary.'));
        }
        $index = DictFields::extractIntArray($dict, 'Index') ?? [0, $size];

        $content = $this->decodeStreamData($raw, $dict);
        $rows = $this->decodeRows($content, $widths, $index);

        $hasObjectStreams = false;
        $objectOffsets = [];
        $rowIndex = 0;
        for ($i = 0; $i < count($index); $i += 2) {
            $startNumber = $index[$i];
            $rangeCount = $index[$i + 1];
            for ($k = 0; $k < $rangeCount; $k++) {
                if (!isset($rows[$rowIndex])) {
                    break;
                }
                [$type, $field2] = $rows[$rowIndex];
                if ($type === 2) {
                    $hasObjectStreams = true;
                } elseif ($type === 1) {
                    $objectOffsets[$startNumber + $k] = $field2;
                }
                $rowIndex++;
            }
        }

        return new XrefLink(
            size: $size,
            root: $root,
            hasEncrypt: DictFields::hasKey($dict, 'Encrypt'),
            hasObjectStreams: $hasObjectStreams,
            isStream: true,
            prevOffset: DictFields::extractInt($dict, 'Prev'),
            objectOffsets: $objectOffsets
        );
    }

    /**
     * @throws LocalizedException
     */
    private function decodeStreamData(string $raw, string $dict): string
    {
        if (!str_contains($dict, '/FlateDecode')) {
            return $raw;
        }
        // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- warning expected beyond the anti-bomb cap
        $content = @gzuncompress($raw, self::MAX_DECOMPRESSED_STREAM);
        if ($content === false) {
            throw new LocalizedException(__('Unsupported PDF: cross-reference stream could not be decompressed.'));
        }

        $decodeParms = DictFields::extractSubDict($dict, 'DecodeParms') ?? DictFields::extractSubDict($dict, 'DP') ?? '';
        $predictor = DictFields::extractInt($decodeParms, 'Predictor') ?? 1;
        if ($predictor === 1) {
            return $content;
        }
        if ($predictor < 10 || $predictor > 15) {
            throw new LocalizedException(
                __('Unsupported PDF: cross-reference stream predictor not handled (value %1).', $predictor)
            );
        }
        $columns = DictFields::extractInt($decodeParms, 'Columns');
        $colors = DictFields::extractInt($decodeParms, 'Colors') ?? 1;
        $bpc = DictFields::extractInt($decodeParms, 'BitsPerComponent') ?? 8;
        if ($columns === null || $colors !== 1 || $bpc !== 8) {
            throw new LocalizedException(
                __('Unsupported PDF: cross-reference stream prediction parameters not handled.')
            );
        }

        return $this->undoPngPrediction($content, $columns);
    }

    /**
     * @param int[] $widths [w1, w2, w3]
     * @param int[] $index pairs [obj_start, count, obj_start, count, ...]
     * @return array<int, array{0: int, 1: int, 2: int}>
     * @throws LocalizedException
     */
    private function decodeRows(string $content, array $widths, array $index): array
    {
        [$w1, $w2, $w3] = $widths;
        $rowWidth = $w1 + $w2 + $w3;
        if ($rowWidth <= 0) {
            throw new LocalizedException(__('Unsupported PDF: invalid cross-reference stream /W widths.'));
        }
        $entryCount = 0;
        for ($i = 0; $i < count($index); $i += 2) {
            $entryCount += $index[$i + 1];
        }
        if (strlen($content) < $rowWidth * $entryCount) {
            throw new LocalizedException(
                __('Unsupported PDF: cross-reference stream too short for the declared entries.')
            );
        }

        $rows = [];
        $pos = 0;
        for ($i = 0; $i < $entryCount; $i++) {
            $row = substr($content, $pos, $rowWidth);
            $type = $w1 === 0 ? 1 : $this->decodeField($row, 0, $w1);
            $field2 = $this->decodeField($row, $w1, $w2);
            $field3 = $this->decodeField($row, $w1 + $w2, $w3);
            $rows[] = [$type, $field2, $field3];
            $pos += $rowWidth;
        }

        return $rows;
    }

    private function decodeField(string $bytes, int $offset, int $width): int
    {
        if ($width === 0) {
            return 0;
        }
        $value = 0;
        for ($i = 0; $i < $width; $i++) {
            $value = ($value << 8) | ord($bytes[$offset + $i]);
        }

        return $value;
    }

    /**
     * @throws LocalizedException
     */
    private function undoPngPrediction(string $content, int $columns): string
    {
        $rowLength = $columns + 1;
        if ($rowLength <= 1 || strlen($content) % $rowLength !== 0) {
            throw new LocalizedException(
                __('Unsupported PDF: cross-reference stream with malformed PNG prediction.')
            );
        }
        $rowCount = intdiv(strlen($content), $rowLength);

        $out = '';
        $prev = str_repeat("\x00", $columns);
        for ($r = 0; $r < $rowCount; $r++) {
            $rowStart = $r * $rowLength;
            $filterType = ord($content[$rowStart]);
            $raw = substr($content, $rowStart + 1, $columns);
            $recon = '';
            for ($x = 0; $x < $columns; $x++) {
                $rawByte = ord($raw[$x]);
                $left = $x > 0 ? ord($recon[$x - 1]) : 0;
                $up = ord($prev[$x]);
                $upLeft = $x > 0 ? ord($prev[$x - 1]) : 0;
                $value = match ($filterType) {
                    0 => $rawByte,
                    1 => $rawByte + $left,
                    2 => $rawByte + $up,
                    3 => $rawByte + intdiv($left + $up, 2),
                    4 => $rawByte + $this->paethPredictor($left, $up, $upLeft),
                    default => throw new LocalizedException(
                        __(
                            'Unsupported PDF: unrecognized PNG row filter in cross-reference stream (%1).',
                            $filterType
                        )
                    ),
                };
                $recon .= chr($value & 0xFF);
            }
            $out .= $recon;
            $prev = $recon;
        }

        return $out;
    }

    private function paethPredictor(int $left, int $up, int $upLeft): int
    {
        $p = $left + $up - $upLeft;
        $predLeft = abs($p - $left);
        $predUp = abs($p - $up);
        $predUpLeft = abs($p - $upLeft);
        if ($predLeft <= $predUp && $predLeft <= $predUpLeft) {
            return $left;
        }
        if ($predUp <= $predUpLeft) {
            return $up;
        }

        return $upLeft;
    }
}
