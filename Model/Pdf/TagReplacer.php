<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Pdf;

use MageOS\DigitalSignature\Model\Pdf\Xref\StreamObjectScanner;
use MageOS\DigitalSignature\Model\Pdf\Xref\XrefChainResolver;
use Magento\Framework\Exception\LocalizedException;

/**
 * Locates the signature tags {WSIGN#W,H#email} in the PDF and replaces the
 * placeholder email with the real one, rewriting the content stream via
 * "incremental update" (appends the modified objects + xref delta, as
 * expected by the PDF standard). Pure PHP, no external dependency.
 *
 * Supports PDFs with a classic cross-reference table (PDF 1.4) and with
 * xref stream (PDF 1.5+, without object streams): the incremental update
 * written uses the same format as the most recent revision of the source
 * PDF.
 */
class TagReplacer
{
    public const TAG_REGEX = '/\{WSIGN#[0-9.,]+#([^}]*)\}/';
    public const FIELD_REGEX = '/\{FIELD:([a-z0-9_]+)\}/';

    /**
     * Cap on the decompressed size of a single FlateDecode stream. Defense
     * against "decompression bombs": a stream of a few KB can expand to
     * hundreds of MB (zlib amplification), bypassing the cap on file size.
     * 50 MB is generous for legitimate streams (even images) but cuts off
     * pathological amplification.
     */
    public function __construct(
        private readonly XrefChainResolver $xrefResolver,
        private readonly IncrementalUpdateWriter $updateWriter,
        private readonly \MageOS\DigitalSignature\Api\MergeFieldPoolInterface $mergeFieldPool,
        private readonly \MageOS\DigitalSignature\Model\Pdf\PreviewOrderContext $previewOrderContext,
        private readonly \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        private readonly StreamObjectScanner $streamObjectScanner = new StreamObjectScanner()
    ) {
    }

    /**
     * Tags found in the PDF (in any stream, even compressed).
     *
     * @return string[] complete tags, e.g. ["{WSIGN#80,20#placeholder@example.com}"]
     */
    public function findTags(string $pdf): array
    {
        $tags = [];
        foreach ($this->streamObjectScanner->scan($pdf) as $object) {
            if (preg_match_all(self::TAG_REGEX, $object['content'], $matches)) {
                array_push($tags, ...$matches[0]);
            }
        }
        // Tags possibly outside the streams (unstructured PDFs)
        if (preg_match_all(self::TAG_REGEX, $pdf, $matches)) {
            foreach ($matches[0] as $tag) {
                if (!in_array($tag, $tags, true)) {
                    $tags[] = $tag;
                }
            }
        }

        return array_values(array_unique($tags));
    }

    /**
     * Merge field tags found in the PDF.
     *
     * @return string[] field codes, e.g. ["order_number", "grand_total"]
     */
    public function findFieldTags(string $pdf): array
    {
        $tags = [];
        foreach ($this->streamObjectScanner->scan($pdf) as $object) {
            if (preg_match_all(self::FIELD_REGEX, $object['content'], $matches)) {
                array_push($tags, ...$matches[1]);
            }
        }
        if (preg_match_all(self::FIELD_REGEX, $pdf, $matches)) {
            foreach ($matches[1] as $tag) {
                if (!in_array($tag, $tags, true)) {
                    $tags[] = $tag;
                }
            }
        }

        return array_values(array_unique($tags));
    }

    /**
     * Replaces the placeholder email of all tags with the real one and the
     * dynamic merge fields.
     *
     * Hybrid strategy: the updated object is appended with a new xref
     * section (incremental update) and the bytes of the original object are
     * blanked out in place with spaces of the SAME length — the offsets of
     * the existing xref remain valid and the placeholder tag is no longer
     * present in the file, not even for text scanners that don't conform to
     * the standard.
     *
     * @throws LocalizedException if no tag is present or the PDF is not supported
     */
    public function replaceSignerEmail(string $pdf, string $email, ?\MageOS\DigitalSignature\Model\MergeField\Context $context = null): string
    {
        if (!str_starts_with($pdf, '%PDF')) {
            throw new LocalizedException(__('The template file is not a PDF.'));
        }
        // The email ends up inside a PDF literal string: escape reserved characters
        $escapedEmail = addcslashes($email, "\\()");
        $replacer = static function (array $m) use ($escapedEmail): string {
            // Reconstruction via concatenation: no escaping issues with the
            // preg_replace replacement string
            $lastHash = strrpos($m[0], '#');

            return substr($m[0], 0, $lastHash) . '#' . $escapedEmail . '}';
        };

        // Pre-blanking snapshot: the resolution of the xref chain must
        // describe the ORIGINAL structure of the document, not the one with
        // the tag objects already blanked out.
        $originalPdf = $pdf;

        $modifiedObjects = [];
        foreach ($this->streamObjectScanner->scan($pdf) as $object) {
            $hasWsign = (bool)preg_match(self::TAG_REGEX, $object['content']);
            $hasField = (bool)preg_match(self::FIELD_REGEX, $object['content']);

            if (!$hasWsign && !$hasField) {
                continue;
            }

            $newContent = $object['content'];
            if ($hasWsign) {
                $newContent = preg_replace_callback(self::TAG_REGEX, $replacer, $newContent);
                // The configured color must be applied ONLY during the actual
                // generation of the document for an order (context not null):
                // the preview/dry-run (null context, see Preview.php and
                // TemplateValidator) must show the tag clearly visible,
                // otherwise the merchant cannot verify its position before
                // confirming the template.
                if ($context !== null && str_contains($newContent, '/MDSHelv1')) {
                    $colorOperator = $this->scopeConfig->getValue(
                        'digital_signature/general/builder_tag_color',
                        \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                        $context->getStoreId()
                    ) ?: '1 g';
                    $newContent = preg_replace('/q\s+BT\s+\/MDSHelv1/', 'q ' . $colorOperator . ' BT /MDSHelv1', $newContent);
                }
            }
            if ($hasField) {
                $fieldReplacer = function (array $m) use ($context): string {
                    $code = $m[1];
                    $actualContext = $context ?? $this->previewOrderContext->getContext();
                    $provider = $this->mergeFieldPool->get($code);
                    $resolved = $provider->resolve($actualContext);
                    return addcslashes($resolved, "\\()");
                };
                $newContent = preg_replace_callback(self::FIELD_REGEX, $fieldReplacer, $newContent);
            }

            $modifiedObjects[] = [
                'number' => $object['number'],
                'body' => $this->updateWriter->buildStreamObject($object['number'], $newContent, $object['compressed']),
            ];
            // Blank out the bytes of the original object while preserving its length
            $pdf = substr_replace($pdf, str_repeat(' ', $object['length']), $object['offset'], $object['length']);
        }
        if (!$modifiedObjects) {
            throw new \MageOS\DigitalSignature\Exception\NoSignatureTagException(
                __('No signature tag found in the template PDF: unable to insert the signer\'s data.')
            );
        }

        return $this->updateWriter->append($pdf, $originalPdf, $modifiedObjects);
    }
}
