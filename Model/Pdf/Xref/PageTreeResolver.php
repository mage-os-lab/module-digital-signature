<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Pdf\Xref;

use Magento\Framework\Exception\LocalizedException;

/**
 * Risolve una pagina per numero (1-based) risalendo Root -> /Pages ->
 * /Kids[N] -> oggetto Page, usando la tabella "numero oggetto => offset"
 * esposta da XrefChainResolver. Supporta solo alberi /Pages "piatti" (Kids
 * che referenzia direttamente le pagine): un nodo /Pages annidato o una
 * pagina con /Rotate diverso da zero sono rifiutati esplicitamente, mai
 * gestiti silenziosamente. /Resources è supportato sia inline sia come
 * riferimento indiretto (oggetto separato risolto tramite la tabella xref);
 * se assente in entrambe le forme, viene rifiutato.
 */
final class PageTreeResolver
{
    public function __construct(private readonly XrefChainResolver $xrefResolver)
    {
    }

    /**
     * @throws LocalizedException
     */
    public function resolve(string $pdf, int $pageNumber): PageInfo
    {
        $info = $this->xrefResolver->resolve($pdf);

        $root = $this->readObjectDict($pdf, $info->objectOffsets, $this->refToObjectNumber($info->root));
        $pagesRef = DictFields::extractRef($root['dict'], 'Pages');
        if ($pagesRef === null) {
            throw new LocalizedException(__('PDF non supportato: catalogo privo di /Pages.'));
        }

        $pages = $this->readObjectDict($pdf, $info->objectOffsets, $this->refToObjectNumber($pagesRef));
        $kids = DictFields::extractRefArray($pages['dict'], 'Kids');
        if ($kids === null || $kids === []) {
            throw new LocalizedException(__('PDF non supportato: /Pages privo di /Kids.'));
        }

        if ($pageNumber < 1 || $pageNumber > count($kids)) {
            throw new LocalizedException(
                __('Numero di pagina %1 non valido: il PDF ne ha %2.', $pageNumber, count($kids))
            );
        }

        $pageObjNumber = $this->refToObjectNumber($kids[$pageNumber - 1]);
        $page = $this->readObjectDict($pdf, $info->objectOffsets, $pageObjNumber);

        if (DictFields::extractName($page['dict'], 'Type') === 'Pages') {
            throw new LocalizedException(
                __('PDF non supportato: struttura pagine annidata non gestita dal builder.')
            );
        }

        $rotate = DictFields::extractInt($page['dict'], 'Rotate') ?? 0;
        if ($rotate !== 0) {
            throw new LocalizedException(
                __('PDF non supportato: pagina ruotata (/Rotate %1) non gestita dal builder.', $rotate)
            );
        }

        $resourcesRef = DictFields::extractRef($page['dict'], 'Resources');
        $resourcesObjectNumber = null;
        if ($resourcesRef !== null) {
            $resourcesObjectNumber = $this->refToObjectNumber($resourcesRef);
            $resourcesObj = $this->readObjectDict($pdf, $info->objectOffsets, $resourcesObjectNumber);
            $resourcesDict = $resourcesObj['dict'];
        } elseif (preg_match('/\/Resources\s*<</', $page['dict'], $resMatch, PREG_OFFSET_CAPTURE)) {
            $resourcesContentStart = $resMatch[0][1] + strlen($resMatch[0][0]);
            [$resourcesDict] = DictFields::extractBalancedDict($page['dict'], $resourcesContentStart);
        } else {
            throw new LocalizedException(
                __('PDF non supportato: pagina priva di /Resources dichiarate.')
            );
        }

        $existingFontName = null;
        if (preg_match('/\/Font\s*<</', $resourcesDict, $fontMatch, PREG_OFFSET_CAPTURE)) {
            $fontContentStart = $fontMatch[0][1] + strlen($fontMatch[0][0]);
            [$fontDict] = DictFields::extractBalancedDict($resourcesDict, $fontContentStart);
            if (preg_match('/\/(\w+)\s+\d+\s+\d+\s+R/', $fontDict, $firstFont)) {
                $existingFontName = $firstFont[1];
            }
        }

        $contentsRef = DictFields::extractRef($page['dict'], 'Contents');
        if ($contentsRef === null) {
            throw new LocalizedException(__('PDF non supportato: pagina priva di /Contents.'));
        }
        $contentsNumber = $this->refToObjectNumber($contentsRef);
        if (!isset($info->objectOffsets[$contentsNumber])) {
            throw new LocalizedException(
                __('PDF non supportato: content stream della pagina non risolvibile.')
            );
        }

        return new PageInfo(
            pageObjectNumber: $pageObjNumber,
            pageObjectOffset: $page['offset'],
            pageObjectLength: $page['length'],
            pageDictText: $page['dict'],
            contentObjectNumber: $contentsNumber,
            contentObjectOffset: $info->objectOffsets[$contentsNumber],
            existingFontResourceName: $existingFontName,
            xrefSize: $info->size,
            resourcesObjectNumber: $resourcesObjectNumber,
            resourcesDictText: $resourcesDict
        );
    }

    /**
     * @param array<int, int> $objectOffsets
     * @return array{dict: string, offset: int, length: int}
     * @throws LocalizedException
     */
    private function readObjectDict(string $pdf, array $objectOffsets, int $objectNumber): array
    {
        if (!isset($objectOffsets[$objectNumber])) {
            throw new LocalizedException(
                __('PDF non supportato: oggetto %1 non risolvibile nella tabella cross-reference.', $objectNumber)
            );
        }
        $offset = $objectOffsets[$objectNumber];
        $region = substr($pdf, $offset);
        if (!preg_match('/^(\d+)\s+(\d+)\s+obj\s*<</', $region, $header)) {
            throw new LocalizedException(
                __('PDF non supportato: oggetto %1 non riconosciuto alla posizione attesa.', $objectNumber)
            );
        }
        $dictContentStart = strlen($header[0]);
        [$dict, $closeStart] = DictFields::extractBalancedDict($region, $dictContentStart);

        $endobjPos = strpos($region, 'endobj', $closeStart + 2);
        if ($endobjPos === false) {
            throw new LocalizedException(
                __('PDF non supportato: oggetto %1 privo di endobj.', $objectNumber)
            );
        }

        return [
            'dict' => $dict,
            'offset' => $offset,
            'length' => $endobjPos + strlen('endobj') - 0,
        ];
    }

    private function refToObjectNumber(string $ref): int
    {
        [$number] = explode(' ', trim($ref), 2);

        return (int)$number;
    }
}
