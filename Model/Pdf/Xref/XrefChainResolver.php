<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Pdf\Xref;

use Magento\Framework\Exception\LocalizedException;

/**
 * Risolve la catena di cross-reference di un PDF (tabella classica e/o xref
 * stream, anche mista), seguendo /Prev fino a un limite fissato. Aggrega
 * hasObjectStreams/isEncrypted su tutta la catena esplorata; Size/Root/
 * isStreamBased vengono dalla revisione più recente (quella da cui parte un
 * eventuale nuovo incremental update).
 */
final class XrefChainResolver
{
    /**
     * Limite di profondità della catena /Prev esplorata: oltre, si rifiuta
     * esplicitamente invece di ignorare silenziosamente revisioni storiche
     * (potrebbero contenere object stream non rilevati). Copre il caso comune
     * (file appena esportato, 0 o 1 revisione precedente).
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
                    'PDF non supportato: catena cross-reference con troppe revisioni collegate (max %1).',
                    self::MAX_CHAIN_DEPTH
                ));
            }
            $link = $this->readLinkAt($pdf, $prevOffset);
            $hasObjectStreams = $hasObjectStreams || $link->hasObjectStreams;
            $hasEncrypt = $hasEncrypt || $link->hasEncrypt;
            // "+=" su array preserva le chiavi già presenti a sinistra: la
            // revisione più recente (già in $objectOffsets) vince sempre.
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
