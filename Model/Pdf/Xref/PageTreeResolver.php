<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Pdf\Xref;

use Magento\Framework\Exception\LocalizedException;

/**
 * Resolves a page by number (1-based) by walking Root -> /Pages ->
 * /Kids[N] -> Page object, using the "object number => offset" table
 * exposed by XrefChainResolver. Supports only "flat" /Pages trees (Kids
 * referencing the pages directly): a nested /Pages node or a page with
 * non-zero /Rotate is explicitly rejected, never handled silently.
 * /Resources is supported both inline and as an indirect reference
 * (separate object resolved via the xref table); if absent in both forms,
 * it is rejected.
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
            throw new LocalizedException(__('Unsupported PDF: catalog is missing /Pages.'));
        }

        $pages = $this->readObjectDict($pdf, $info->objectOffsets, $this->refToObjectNumber($pagesRef));
        $kids = DictFields::extractRefArray($pages['dict'], 'Kids');
        if ($kids === null || $kids === []) {
            throw new LocalizedException(__('Unsupported PDF: /Pages is missing /Kids.'));
        }

        if ($pageNumber < 1 || $pageNumber > count($kids)) {
            throw new LocalizedException(
                __('Invalid page number %1: the PDF has %2.', $pageNumber, count($kids))
            );
        }

        $pageObjNumber = $this->refToObjectNumber($kids[$pageNumber - 1]);
        $page = $this->readObjectDict($pdf, $info->objectOffsets, $pageObjNumber);

        if (DictFields::extractName($page['dict'], 'Type') === 'Pages') {
            throw new LocalizedException(
                __('Unsupported PDF: nested page structure not handled by the builder.')
            );
        }

        $rotate = DictFields::extractInt($page['dict'], 'Rotate') ?? 0;
        if ($rotate !== 0) {
            throw new LocalizedException(
                __('Unsupported PDF: rotated page (/Rotate %1) not handled by the builder.', $rotate)
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
                __('Unsupported PDF: page has no declared /Resources.')
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
            throw new LocalizedException(__('Unsupported PDF: page is missing /Contents.'));
        }
        $contentsNumber = $this->refToObjectNumber($contentsRef);
        if (!isset($info->objectOffsets[$contentsNumber])) {
            throw new LocalizedException(
                __('Unsupported PDF: page content stream could not be resolved.')
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
                __('Unsupported PDF: object %1 could not be resolved in the cross-reference table.', $objectNumber)
            );
        }
        $offset = $objectOffsets[$objectNumber];
        $region = substr($pdf, $offset);
        if (!preg_match('/^(\d+)\s+(\d+)\s+obj\s*<</', $region, $header)) {
            throw new LocalizedException(
                __('Unsupported PDF: object %1 not recognized at the expected position.', $objectNumber)
            );
        }
        $dictContentStart = strlen($header[0]);
        [$dict, $closeStart] = DictFields::extractBalancedDict($region, $dictContentStart);

        $endobjPos = strpos($region, 'endobj', $closeStart + 2);
        if ($endobjPos === false) {
            throw new LocalizedException(
                __('Unsupported PDF: object %1 is missing endobj.', $objectNumber)
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
