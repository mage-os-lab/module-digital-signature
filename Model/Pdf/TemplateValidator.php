<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Pdf;

use MageOS\DigitalSignature\Model\Pdf\Xref\XrefChainResolver;
use Magento\Framework\Exception\LocalizedException;

/**
 * Validation of the template PDF on upload, BEFORE making it available
 * (analysis decision §13-bis): cheap checks on size/magic bytes, explicit
 * rejection of encrypted PDFs or PDFs with object compression (object
 * stream, not supported at this stage), finally a real dry-run of the tag
 * replacement with a placeholder email — actual execution of the same
 * write path used in production, not just a presence check: an accepted
 * template can no longer fail for structural reasons during the processing
 * of a real order.
 */
class TemplateValidator
{
    private const MAX_SIZE_BYTES = 10485760; // aligned with the form's maxFileSize

    /**
     * Placeholder email used both by the validation dry-run and by the
     * downloadable preview (Controller/Adminhtml/Template/Preview): same
     * constant, same deterministic output.
     */
    public const PREVIEW_SIGNER_EMAIL = 'anteprima.firmatario@esempio-dominio-lungo.test';

    public function __construct(
        private readonly TagReplacer $tagReplacer,
        private readonly XrefChainResolver $xrefResolver
    ) {
    }

    /**
     * @throws LocalizedException with a merchant-facing message
     */
    public function validate(string $pdfContent): void
    {
        if (strlen($pdfContent) > self::MAX_SIZE_BYTES) {
            throw new LocalizedException(__('The PDF exceeds the maximum size of 10 MB.'));
        }
        if (!str_starts_with($pdfContent, '%PDF')) {
            throw new LocalizedException(__('The uploaded file is not a valid PDF.'));
        }

        $info = $this->xrefResolver->resolve($pdfContent);
        if ($info->isEncrypted) {
            throw new LocalizedException(__(
                'Encrypted PDFs are not supported: remove the protection/password from the document and re-upload the file.'
            ));
        }
        if ($info->hasObjectStreams) {
            throw new LocalizedException(__(
                'Unsupported PDF: it uses object compression (object streams), not handled in this version. Disable object compression when exporting and re-upload the file.'
            ));
        }

        // Real dry-run: same write path as production, placeholder email,
        // result discarded. Raises its own LocalizedException (invalid PDF /
        // no tag) if the replacement is not possible.
        $this->tagReplacer->replaceSignerEmail($pdfContent, self::PREVIEW_SIGNER_EMAIL);
    }
}
