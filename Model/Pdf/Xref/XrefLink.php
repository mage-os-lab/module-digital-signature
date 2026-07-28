<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Pdf\Xref;

/**
 * Result of reading ONE cross-reference section (classic table or xref
 * stream), before following any /Prev chain.
 */
final class XrefLink
{
    /**
     * @param array<int, int> $objectOffsets object number => byte offset in the file,
     *        only for the "in use" objects of this section (not the whole chain)
     */
    public function __construct(
        public readonly int $size,
        public readonly string $root,
        public readonly bool $hasEncrypt,
        public readonly bool $hasObjectStreams,
        public readonly bool $isStream,
        public readonly ?int $prevOffset,
        public readonly array $objectOffsets = []
    ) {
    }
}
