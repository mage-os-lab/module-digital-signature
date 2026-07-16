<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Pdf\Xref;

/**
 * Esito della risoluzione dell'intera catena di cross-reference di un PDF
 * (tabella/stream più recente + storico entro il limite supportato).
 */
final class XrefInfo
{
    /**
     * @param array<int, int> $objectOffsets numero oggetto => offset byte, fuso su
     *        tutta la catena esplorata (la revisione più recente vince)
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
