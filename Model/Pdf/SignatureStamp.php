<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Pdf;

use MageOS\DigitalSignature\Model\Pdf\Xref\DictFields;
use MageOS\DigitalSignature\Model\Pdf\Xref\PageTreeResolver;
use MageOS\DigitalSignature\Model\Pdf\Xref\StreamObjectScanner;

/**
 * Draws a black-bordered box with a short line of text at the exact
 * position of an already-placed {WSIGN#W,H#email} signature tag: used by
 * the Dummy provider to simulate, cosmetically, what a real provider embeds
 * in the document at signing time. Pure PHP, no external dependency, same
 * incremental-update mechanism as SignatureTagInjector/TagReplacer.
 *
 * Never blocks signing over a cosmetic feature: any PDF it cannot parse
 * (tag missing, unsupported page structure, ...) is returned unchanged.
 */
class SignatureStamp
{
    private const NEW_FONT_RESOURCE_NAME = 'MDSStamp1';
    private const FONT_SIZE_PT = 8;
    private const TEXT_PADDING_PT = 2.0;
    private const MM_TO_POINT = 72 / 25.4;

    /**
     * Matches the exact "X Y Td (tag) Tj" sequence SignatureTagInjector
     * writes: the operand order and the tag content are the only two things
     * it needs, the font/BT/ET wrapping around it (and any color operator
     * TagReplacer may have inserted) are irrelevant here.
     */
    private const TAG_POSITION_REGEX =
        '/(-?[0-9.]+)\s+(-?[0-9.]+)\s+Td\s*\(\{WSIGN#([0-9.]+),([0-9.]+)#[^)]*\}\)\s*Tj/';

    public function __construct(
        private readonly PageTreeResolver $pageResolver,
        private readonly IncrementalUpdateWriter $updateWriter,
        private readonly StreamObjectScanner $streamObjectScanner = new StreamObjectScanner()
    ) {
    }

    public function stampAt(string $pdf, string $text): string
    {
        $tag = $this->locateTag($pdf);
        if ($tag === null) {
            return $pdf;
        }

        try {
            return $this->draw($pdf, $tag, $text);
        } catch (\Exception) {
            return $pdf;
        }
    }

    /**
     * @param array{contentObjectNumber: int, x: float, y: float, widthMm: float, heightMm: float} $tag
     * @throws \Exception any failure here is caught by stampAt() and treated as a no-op
     */
    private function draw(string $pdf, array $tag, string $text): string
    {
        $pageNumber = $this->pageResolver->findPageNumberByContentObject($pdf, $tag['contentObjectNumber']);
        if ($pageNumber === null) {
            return $pdf;
        }
        $page = $this->pageResolver->resolve($pdf, $pageNumber);

        $widthPt = $tag['widthMm'] * self::MM_TO_POINT;
        $heightPt = $tag['heightMm'] * self::MM_TO_POINT;
        $x = $tag['x'];
        $y = $tag['y'];

        $modifiedObjects = [];
        $fontName = $page->existingFontResourceName;
        if ($fontName === null) {
            $fontName = self::NEW_FONT_RESOURCE_NAME;
            $fontObjectNumber = $page->xrefSize;
            $modifiedObjects[] = [
                'number' => $fontObjectNumber,
                'body' => sprintf(
                    "%d 0 obj\n<</Type /Font /Subtype /Type1 /BaseFont /Helvetica>>\nendobj\n",
                    $fontObjectNumber
                ),
            ];
            if ($page->resourcesObjectNumber !== null) {
                $modifiedObjects[] = [
                    'number' => $page->resourcesObjectNumber,
                    'body' => sprintf(
                        "%d 0 obj\n<<%s>>\nendobj\n",
                        $page->resourcesObjectNumber,
                        $this->addFontToResourcesContent($page->resourcesDictText, $fontObjectNumber)
                    ),
                ];
                $pageDict = $page->pageDictText;
            } else {
                $pageDict = $this->addFontResource($page->pageDictText, $fontObjectNumber);
            }
            $newContentNumber = $page->xrefSize + 1;
        } else {
            $pageDict = $page->pageDictText;
            $newContentNumber = $page->xrefSize;
        }

        $streamContent = $this->buildStampStream($x, $y, $widthPt, $heightPt, $fontName, $text);
        $newContentObject = $this->updateWriter->buildStreamObject($newContentNumber, $streamContent, false);
        $newPageDict = $this->addContentToPage($pageDict, $newContentNumber);

        $modifiedObjects[] = [
            'number' => $page->pageObjectNumber,
            'body' => sprintf("%d 0 obj\n<<%s>>\nendobj\n", $page->pageObjectNumber, $newPageDict),
        ];
        $modifiedObjects[] = ['number' => $newContentNumber, 'body' => $newContentObject];

        return $this->updateWriter->append($pdf, $pdf, $modifiedObjects);
    }

    /**
     * @return array{contentObjectNumber: int, x: float, y: float, widthMm: float, heightMm: float}|null
     */
    private function locateTag(string $pdf): ?array
    {
        foreach ($this->streamObjectScanner->scan($pdf) as $object) {
            if (preg_match(self::TAG_POSITION_REGEX, $object['content'], $m)) {
                return [
                    'contentObjectNumber' => $object['number'],
                    'x' => (float)$m[1],
                    'y' => (float)$m[2],
                    'widthMm' => (float)$m[3],
                    'heightMm' => (float)$m[4],
                ];
            }
        }

        return null;
    }

    private function buildStampStream(
        float $x,
        float $y,
        float $widthPt,
        float $heightPt,
        string $fontName,
        string $text
    ): string {
        $escapedText = addcslashes($text, "\\()");

        return sprintf(
            'q 0 0 0 RG 1 w %s %s %s %s re S Q ' .
            'q BT /%s %d Tf %s %s Td (%s) Tj ET Q',
            $this->formatNumber($x),
            $this->formatNumber($y),
            $this->formatNumber($widthPt),
            $this->formatNumber($heightPt),
            $fontName,
            self::FONT_SIZE_PT,
            $this->formatNumber($x + self::TEXT_PADDING_PT),
            $this->formatNumber($y + self::TEXT_PADDING_PT),
            $escapedText
        );
    }

    /**
     * Replaces "/Contents N 0 R" with "/Contents [N 0 R M 0 R]", or appends
     * "M 0 R" to an already-present array (a template that already received
     * a tag, the common case by the time a document reaches signing).
     */
    private function addContentToPage(string $pageDict, int $newContentNumber): string
    {
        $singleRefPattern = '/\/Contents\s+(\d+)\s+0\s+R/';
        if (preg_match($singleRefPattern, $pageDict, $m)) {
            return preg_replace(
                $singleRefPattern,
                sprintf('/Contents [%d 0 R %d 0 R]', (int)$m[1], $newContentNumber),
                $pageDict,
                1
            );
        }

        return preg_replace(
            '/(\/Contents\s*\[)(.*?)(\])/s',
            sprintf('${1}${2} %d 0 R${3}', $newContentNumber),
            $pageDict,
            1
        );
    }

    private function addFontResource(string $pageDict, int $fontObjectNumber): string
    {
        preg_match('/\/Resources\s*<</', $pageDict, $resMatch, PREG_OFFSET_CAPTURE);
        $resourcesContentStart = $resMatch[0][1] + strlen($resMatch[0][0]);
        [$resourcesContent, $resourcesContentEnd] = DictFields::extractBalancedDict($pageDict, $resourcesContentStart);

        return substr($pageDict, 0, $resourcesContentStart)
            . $this->addFontToResourcesContent($resourcesContent, $fontObjectNumber)
            . substr($pageDict, $resourcesContentEnd);
    }

    private function addFontToResourcesContent(string $resourcesContent, int $fontObjectNumber): string
    {
        if (preg_match('/\/Font\s*<</', $resourcesContent, $fontMatch, PREG_OFFSET_CAPTURE)) {
            $fontContentStart = $fontMatch[0][1] + strlen($fontMatch[0][0]);

            return substr($resourcesContent, 0, $fontContentStart)
                . sprintf('/%s %d 0 R ', self::NEW_FONT_RESOURCE_NAME, $fontObjectNumber)
                . substr($resourcesContent, $fontContentStart);
        }

        return $resourcesContent
            . sprintf(' /Font <</%s %d 0 R>>', self::NEW_FONT_RESOURCE_NAME, $fontObjectNumber);
    }

    private function formatNumber(float $value): string
    {
        $formatted = rtrim(rtrim(sprintf('%.2F', round($value, 2)), '0'), '.');

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }
}
