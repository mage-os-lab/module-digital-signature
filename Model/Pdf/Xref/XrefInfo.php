<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Pdf\Xref;

/**
 * Result of resolving the entire cross-reference chain of a PDF (most
 * recent table/stream + history within the supported limit).
 */
final class XrefInfo
{
    /**
     * @param array<int, int> $objectOffsets object number => byte offset, merged over
     *        the whole explored chain (the most recent revision wins)
     */
    public function __construct(
        public readonly int $size,
        public readonly string $root,
        public readonly bool $hasObjectStreams,
        public readonly bool $isEncrypted,
        public readonly bool $isStreamBased,
        public readonly array $objectOffsets = []
    ) {
    }
}
