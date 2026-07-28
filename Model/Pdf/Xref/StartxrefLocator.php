<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Pdf\Xref;

use Magento\Framework\Exception\LocalizedException;

/**
 * Locates the last "startxref" in the file: entry point of the most recent
 * cross-reference chain (classic table or stream).
 */
final class StartxrefLocator
{
    /**
     * @throws LocalizedException
     */
    public static function locate(string $pdf): int
    {
        $tail = substr($pdf, -256);
        if (!preg_match_all('/startxref\s+(\d+)/s', $tail, $matches) || !$matches[1]) {
            throw new LocalizedException(__('Unsupported PDF: startxref not found.'));
        }

        return (int)end($matches[1]);
    }
}
