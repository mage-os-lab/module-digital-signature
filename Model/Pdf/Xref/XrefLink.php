<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Pdf\Xref;

/**
 * Esito della lettura di UNA sezione di cross-reference (tabella classica o
 * xref stream), prima di seguire l'eventuale catena /Prev.
 */
final class XrefLink
{
    /**
     * @param array<int, int> $objectOffsets numero oggetto => offset byte nel file,
     *        solo per gli oggetti "in use" di questa sezione (non l'intera catena)
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
