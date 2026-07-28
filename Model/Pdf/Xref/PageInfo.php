<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Pdf\Xref;

/**
 * Result of resolving a page via PageTreeResolver: identifies the Page
 * object and its content stream, and exposes what SignatureTagInjector
 * needs to decide whether to reuse an existing font or add a new one.
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
         * Number of the /Resources object when declared as an indirect
         * reference (e.g. "/Resources 5 0 R"), null when inline in the page
         * dictionary: distinguishes where SignatureTagInjector must write
         * the newly added font.
         */
        public readonly ?int $resourcesObjectNumber = null,
        /**
         * Text content of the /Resources dictionary (without the << >>
         * delimiters), resolved whether it is inline or indirect.
         */
        public readonly string $resourcesDictText = ''
    ) {
    }
}
