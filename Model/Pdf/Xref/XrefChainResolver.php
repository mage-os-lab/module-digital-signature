<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Pdf\Xref;

use Magento\Framework\Exception\LocalizedException;

/**
 * Resolves the cross-reference chain of a PDF (classic table and/or xref
 * stream, possibly mixed), following /Prev up to a fixed limit. Aggregates
 * hasObjectStreams/isEncrypted over the whole explored chain; Size/Root/
 * isStreamBased come from the most recent revision (the one a new
 * incremental update, if any, would start from).
 */
final class XrefChainResolver
{
    /**
     * Depth limit of the explored /Prev chain: beyond it, explicitly rejects
     * instead of silently ignoring historical revisions (they could contain
     * undetected object streams). Covers the common case (freshly exported
     * file, 0 or 1 previous revision).
     */
    private const MAX_CHAIN_DEPTH = 2;

    public function __construct(
        private readonly ClassicXrefReader $classicReader,
        private readonly XrefStreamReader $streamReader
    ) {
    }

    /**
     * @throws LocalizedException
     */
    public function resolve(string $pdf): XrefInfo
    {
        $offset = StartxrefLocator::locate($pdf);
        $current = $this->readLinkAt($pdf, $offset);

        $hasObjectStreams = $current->hasObjectStreams;
        $hasEncrypt = $current->hasEncrypt;
        $objectOffsets = $current->objectOffsets;
        $prevOffset = $current->prevOffset;
        $depth = 1;
        while ($prevOffset !== null) {
            if ($depth >= self::MAX_CHAIN_DEPTH) {
                throw new LocalizedException(__(
                    'Unsupported PDF: cross-reference chain has too many linked revisions (max %1).',
                    self::MAX_CHAIN_DEPTH
                ));
            }
            $link = $this->readLinkAt($pdf, $prevOffset);
            $hasObjectStreams = $hasObjectStreams || $link->hasObjectStreams;
            $hasEncrypt = $hasEncrypt || $link->hasEncrypt;
            // "+=" on an array preserves keys already present on the left: the
            // most recent revision (already in $objectOffsets) always wins.
            $objectOffsets += $link->objectOffsets;
            $prevOffset = $link->prevOffset;
            $depth++;
        }

        return new XrefInfo(
            size: $current->size,
            root: $current->root,
            hasObjectStreams: $hasObjectStreams,
            isEncrypted: $hasEncrypt,
            isStreamBased: $current->isStream,
            objectOffsets: $objectOffsets
        );
    }

    private function readLinkAt(string $pdf, int $offset): XrefLink
    {
        return substr($pdf, $offset, 4) === 'xref'
            ? $this->classicReader->readAt($pdf, $offset)
            : $this->streamReader->readAt($pdf, $offset);
    }
}
