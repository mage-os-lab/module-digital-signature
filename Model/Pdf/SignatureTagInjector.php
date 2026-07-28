<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Pdf;

use MageOS\DigitalSignature\Model\Pdf\Xref\DictFields;
use MageOS\DigitalSignature\Model\Pdf\Xref\PageTreeResolver;
use Magento\Framework\Exception\LocalizedException;

/**
 * Inserts a signature tag {WSIGN#W,H#email} at a precise point on a page,
 * writing real PDF text (Tj, with an actual font) via the same incremental
 * update mechanism already used by TagReplacer. If the page does not
 * already have a declared font, it adds a standard one (Helvetica, no
 * embedding needed). Pure PHP, no external dependency.
 */
class SignatureTagInjector
{
    /**
     * Placeholder email written into the tag just inserted: replaced later
     * by TagReplacer::replaceSignerEmail() with the signer's real email,
     * exactly as for a tag written by hand by the merchant. Distinct from
     * TemplateValidator::PREVIEW_SIGNER_EMAIL, which serves a different
     * purpose (fixed email used ONLY for the validation/preview dry-run).
     */
    public const PLACEHOLDER_EMAIL = 'firmatario@da-impostare.invalid';

    private const NEW_FONT_RESOURCE_NAME = 'MDSHelv1';
    private const FONT_SIZE_PT = 10;

    public function __construct(
        private readonly PageTreeResolver $pageResolver,
        private readonly IncrementalUpdateWriter $updateWriter
    ) {
    }

    /**
     * Inserts the tag as an ADDITIONAL content stream (does not modify the
     * existing content stream): the page is rewritten with /Contents as an
     * array [original-content, new-tag-content], as expected by the PDF
     * standard (the content streams of an array are concatenated by the
     * viewer). Avoids having to read/decompress the original content
     * stream, which may use unknown filters or conventions.
     *
     * @throws LocalizedException
     */
    public function injectAt(
        string $pdf,
        int $pageNumber,
        float $xPoints,
        float $yPoints,
        float $widthMm,
        float $heightMm
    ): string {
        $page = $this->pageResolver->resolve($pdf, $pageNumber);
        $originalPdf = $pdf;

        $tag = sprintf(
            '{WSIGN#%s,%s#%s}',
            $this->formatNumber($widthMm),
            $this->formatNumber($heightMm),
            self::PLACEHOLDER_EMAIL
        );
        $escapedTag = addcslashes($tag, "\\()");
        $fontName = self::NEW_FONT_RESOURCE_NAME;

        $tagStreamContent = sprintf(
            'q BT /%s %d Tf %s %s Td (%s) Tj ET Q',
            $fontName,
            self::FONT_SIZE_PT,
            $this->formatNumber($xPoints),
            $this->formatNumber($yPoints),
            $escapedTag
        );

        // First object number never used in the current revision: guaranteed
        // free, does not require scanning the whole file to find one.
        $newContentNumber = $page->xrefSize;
        $newContentObject = $this->updateWriter->buildStreamObject($newContentNumber, $tagStreamContent, false);

        $newPageDict = $this->addContentToPage($page->pageDictText, $page->contentObjectNumber, $newContentNumber);

        // $newContentNumber has already claimed $page->xrefSize in this same
        // incremental update: the font, to avoid colliding, takes the next
        // number, also never used in any previous revision.
        $fontObjectNumber = $page->xrefSize + 1;

        $modifiedObjects = [
            [
                'number' => $fontObjectNumber,
                'body' => sprintf(
                    "%d 0 obj\n<</Type /Font /Subtype /Type1 /BaseFont /Helvetica>>\nendobj\n",
                    $fontObjectNumber
                ),
            ]
        ];

        if ($page->resourcesObjectNumber !== null) {
            // /Resources is a separate object (indirect reference): the font
            // must be written there, the page dictionary stays unchanged
            // apart from the /Contents patch.
            $newResourcesContent = $this->addFontToResourcesContent($page->resourcesDictText, $fontObjectNumber);
            $modifiedObjects[] = [
                'number' => $page->resourcesObjectNumber,
                'body' => sprintf(
                    "%d 0 obj\n<<%s>>\nendobj\n",
                    $page->resourcesObjectNumber,
                    $newResourcesContent
                ),
            ];
        } else {
            $newPageDict = $this->addFontResource($newPageDict, $fontObjectNumber);
        }

        $newPageObject = sprintf("%d 0 obj\n<<%s>>\nendobj\n", $page->pageObjectNumber, $newPageDict);

        $modifiedObjects[] = ['number' => $page->pageObjectNumber, 'body' => $newPageObject];
        $modifiedObjects[] = ['number' => $newContentNumber, 'body' => $newContentObject];

        return $this->updateWriter->append($pdf, $originalPdf, $modifiedObjects);
    }

    /**
     * Adds "/Font <</MDSHelv1 N 0 R>>" to the /Resources dictionary already
     * present in the page dictionary text (guaranteed by PageTreeResolver),
     * correctly balancing any nested sub-dictionaries.
     *
     * @throws LocalizedException
     */
    private function addFontResource(string $pageDict, int $fontObjectNumber): string
    {
        if (!preg_match('/\/Resources\s*<</', $pageDict, $resMatch, PREG_OFFSET_CAPTURE)) {
            // Defensive: PageTreeResolver has already guaranteed the presence of inline /Resources.
            throw new LocalizedException(__('Unsupported PDF: /Resources not found for the update.'));
        }
        $resourcesContentStart = $resMatch[0][1] + strlen($resMatch[0][0]);
        [$resourcesContent, $resourcesContentEnd] = DictFields::extractBalancedDict($pageDict, $resourcesContentStart);

        $newResourcesContent = $this->addFontToResourcesContent($resourcesContent, $fontObjectNumber);

        return substr($pageDict, 0, $resourcesContentStart)
            . $newResourcesContent
            . substr($pageDict, $resourcesContentEnd);
    }

    /**
     * Inserts the reference to the new font into the text content (already
     * isolated, without << >> delimiters) of a /Resources dictionary,
     * regardless of whether it comes from the inline page dictionary or
     * from a separate /Resources object.
     */
    private function addFontToResourcesContent(string $resourcesContent, int $fontObjectNumber): string
    {
        if (preg_match('/\/Font\s*<</', $resourcesContent, $fontMatch, PREG_OFFSET_CAPTURE)) {
            // If the /Font dictionary already exists, insert our font inside it
            $fontContentStart = $fontMatch[0][1] + strlen($fontMatch[0][0]);

            return substr($resourcesContent, 0, $fontContentStart)
                . sprintf('/%s %d 0 R ', self::NEW_FONT_RESOURCE_NAME, $fontObjectNumber)
                . substr($resourcesContent, $fontContentStart);
        }

        // Otherwise add the complete /Font dictionary
        return $resourcesContent
            . sprintf(' /Font <</%s %d 0 R>>', self::NEW_FONT_RESOURCE_NAME, $fontObjectNumber);
    }

    /**
     * Replaces "/Contents N 0 R" with "/Contents [N 0 R M 0 R]" in the page
     * dictionary text, where M is the number of the new content stream.
     *
     * @throws LocalizedException
     */
    private function addContentToPage(string $pageDict, int $existingContentNumber, int $newContentNumber): string
    {
        $pattern = '/\/Contents\s+' . $existingContentNumber . '\s+0\s+R/';
        $replacement = sprintf('/Contents [%d 0 R %d 0 R]', $existingContentNumber, $newContentNumber);
        $newDict = preg_replace($pattern, $replacement, $pageDict, 1, $count);
        if ($count !== 1) {
            throw new LocalizedException(
                __('Unsupported PDF: page /Contents not recognized for tag insertion.')
            );
        }

        return $newDict;
    }

    private function formatNumber(float $value): string
    {
        $formatted = rtrim(rtrim(sprintf('%.2F', round($value, 2)), '0'), '.');

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }
}
