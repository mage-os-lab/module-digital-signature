<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Pdf\Xref;

use Magento\Framework\Exception\LocalizedException;

/**
 * Reads a classic cross-reference section (text table + trailer) starting
 * from a known offset: Size/Root/Encrypt/Prev of the associated trailer,
 * and the "object number => offset" table for the "in use" entries only
 * (used by PageTreeResolver to resolve objects by number; TagReplacer
 * continues to find its own objects via a direct text scan).
 */
final class ClassicXrefReader
{
    /**
     * @throws LocalizedException
     */
    public function readAt(string $pdf, int $offset): XrefLink
    {
        if (substr($pdf, $offset, 4) !== 'xref') {
            throw new LocalizedException(
                __('Unsupported PDF: classic cross-reference section not found at the expected position.')
            );
        }
        if (!preg_match('/trailer\s*<<(.*?)>>/s', $pdf, $matches, 0, $offset)) {
            throw new LocalizedException(
                __('Unsupported PDF: trailer not found after the cross-reference table.')
            );
        }
        $dict = $matches[1];
        $size = DictFields::extractInt($dict, 'Size');
        $root = DictFields::extractRef($dict, 'Root');
        if ($size === null || $root === null) {
            throw new LocalizedException(__('Unsupported PDF: trailer missing Size/Root.'));
        }

        $trailerPos = strpos($pdf, 'trailer', $offset);
        $body = substr($pdf, $offset + 4, $trailerPos - $offset - 4);

        return new XrefLink(
            size: $size,
            root: $root,
            hasEncrypt: DictFields::hasKey($dict, 'Encrypt'),
            hasObjectStreams: false,
            isStream: false,
            prevOffset: DictFields::extractInt($dict, 'Prev'),
            objectOffsets: $this->parseObjectOffsets($body)
        );
    }

    /**
     * @return array<int, int> object number => offset, only "n" entries (in use)
     */
    private function parseObjectOffsets(string $body): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($body)) ?: [];
        $offsets = [];
        $count = count($lines);
        $i = 0;
        while ($i < $count) {
            $line = trim($lines[$i]);
            $i++;
            if ($line === '' || !preg_match('/^(\d+)\s+(\d+)$/', $line, $header)) {
                continue;
            }
            $start = (int)$header[1];
            $entryCount = (int)$header[2];
            for ($k = 0; $k < $entryCount && $i < $count; $k++, $i++) {
                $entry = trim($lines[$i]);
                if (!preg_match('/^(\d{10})\s+(\d{5})\s+([nf])/', $entry, $em)) {
                    continue;
                }
                if ($em[3] === 'n') {
                    $offsets[$start + $k] = (int)$em[1];
                }
            }
        }

        return $offsets;
    }
}
