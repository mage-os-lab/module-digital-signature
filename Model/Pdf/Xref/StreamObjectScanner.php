<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Pdf\Xref;

/**
 * Extracts a PDF's stream objects with decompressed content and the
 * object's position/length in the file. Shared by TagReplacer (tag lookup
 * and email replacement) and SignatureStamp (locating the tag's exact
 * position to draw the dummy-provider stamp).
 */
class StreamObjectScanner
{
    /**
     * Cap on the decompressed size of a single FlateDecode stream. Defense
     * against "decompression bombs": a stream of a few KB can expand to
     * hundreds of MB (zlib amplification), bypassing the cap on file size.
     * 50 MB is generous for legitimate streams (even images) but cuts off
     * pathological amplification.
     */
    private const MAX_DECOMPRESSED_STREAM = 52428800;

    /**
     * Objects are delimited by explicit boundaries (header -> endobj), NEVER
     * with a single multi-object regex: lazy backtracking quantifiers can
     * cross the boundaries and corrupt the extraction.
     *
     * @return array<int, array{number: int, content: string, compressed: bool, offset: int, length: int}>
     */
    public function scan(string $pdf): array
    {
        $objects = [];
        if (!preg_match_all('/(\d+)\s+\d+\s+obj\b/', $pdf, $headers, PREG_OFFSET_CAPTURE)) {
            return $objects;
        }

        $headerCount = count($headers[0]);
        for ($i = 0; $i < $headerCount; $i++) {
            $regionStart = $headers[0][$i][1];
            $regionEnd = $i + 1 < $headerCount ? $headers[0][$i + 1][1] : strlen($pdf);
            $region = substr($pdf, $regionStart, $regionEnd - $regionStart);

            // The object ends at endobj: excludes xref/trailer at the end of the file
            $endobjPos = strrpos($region, 'endobj');
            if ($endobjPos === false) {
                continue;
            }
            $region = substr($region, 0, $endobjPos + strlen('endobj'));

            // Start of stream data: "stream" keyword right after the dictionary
            if (!preg_match('/>>\s*stream(\r\n|\n)/', $region, $streamMatch, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $dataStart = $streamMatch[0][1] + strlen($streamMatch[0][0]);
            $endstreamPos = strrpos($region, 'endstream');
            if ($endstreamPos === false || $endstreamPos <= $dataStart) {
                continue;
            }
            $dataEnd = $endstreamPos;
            if (substr($region, $dataEnd - 1, 1) === "\n") {
                $dataEnd--;
                if (substr($region, $dataEnd - 1, 1) === "\r") {
                    $dataEnd--;
                }
            }
            $raw = substr($region, $dataStart, $dataEnd - $dataStart);
            $dict = substr($region, 0, $streamMatch[0][1]);

            $compressed = str_contains($dict, '/FlateDecode');
            if ($compressed) {
                // FlateDecode = zlib (RFC 1950); if the inflate fails, or if the
                // decompressed stream exceeds the anti-bomb cap, the object is
                // skipped (never corrupted)
                // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- the warning on non-zlib/over-cap streams is the expected fallback
                $content = @gzuncompress($raw, self::MAX_DECOMPRESSED_STREAM);
                if ($content === false) {
                    continue;
                }
            } else {
                $content = $raw;
            }
            $objects[] = [
                'number' => (int)$headers[1][$i][0],
                'content' => $content,
                'compressed' => $compressed,
                'offset' => $regionStart,
                'length' => strlen($region),
            ];
        }

        return $objects;
    }
}
