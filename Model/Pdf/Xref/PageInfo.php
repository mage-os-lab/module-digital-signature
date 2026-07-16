<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Pdf\Xref;

/**
 * Esito della risoluzione di una pagina tramite PageTreeResolver: identifica
 * l'oggetto Page e il suo content stream, ed espone quanto serve a
 * SignatureTagInjector per decidere se riusare un font esistente o
 * aggiungerne uno nuovo.
 */
final class PageInfo
{
    public function __construct(
        public readonly int $pageObjectNumber,
        public readonly int $pageObjectOffset,
        public readonly int $pageObjectLength,
        public readonly string $pageDictText,
        public readonly int $contentObjectNumber,
        public readonly int $contentObjectOffset,
        public readonly ?string $existingFontResourceName,
        public readonly int $xrefSize,
        /**
         * Numero dell'oggetto /Resources quando dichiarate come riferimento
         * indiretto (es. "/Resources 5 0 R"), null quando inline nel
         * dizionario pagina: distingue dove SignatureTagInjector deve
         * scrivere il nuovo font aggiunto.
         */
        public readonly ?int $resourcesObjectNumber = null,
        /**
         * Contenuto testuale del dizionario /Resources (senza i delimitatori
         * << >>), risolto sia che sia inline sia indiretto.
         */
        public readonly string $resourcesDictText = ''
    ) {
    }
}
