<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Pdf\Xref;

use Magento\Framework\Exception\LocalizedException;

/**
 * Extraction of fields from a text PDF dictionary already isolated as a
 * string (e.g. the content between "<<" and ">>" of an object). Read-only,
 * no complete structural validation: used by the xref readers.
 */
final class DictFields
{
    public static function extractInt(string $dict, string $key): ?int
    {
        if (preg_match('/\/' . preg_quote($key, '/') . '(?![A-Za-z0-9])\s+(-?\d+)/', $dict, $m)) {
            return (int)$m[1];
        }

        return null;
    }

    /**
     * @return int[]|null
     */
    public static function extractIntArray(string $dict, string $key): ?array
    {
        if (!preg_match('/\/' . preg_quote($key, '/') . '(?![A-Za-z0-9])\s*\[([^\]]*)\]/', $dict, $m)) {
            return null;
        }
        $values = array_values(array_filter(preg_split('/\s+/', trim($m[1])), static fn ($v) => $v !== ''));

        return array_map('intval', $values);
    }

    public static function extractRef(string $dict, string $key): ?string
    {
        if (preg_match('/\/' . preg_quote($key, '/') . '(?![A-Za-z0-9])\s+(\d+\s+\d+\s+R)/', $dict, $m)) {
            return $m[1];
        }

        return null;
    }

    public static function extractSubDict(string $dict, string $key): ?string
    {
        if (preg_match('/\/' . preg_quote($key, '/') . '(?![A-Za-z0-9])\s*<<(.*?)>>/s', $dict, $m)) {
            return $m[1];
        }

        return null;
    }

    public static function hasKey(string $dict, string $key): bool
    {
        return (bool)preg_match('/\/' . preg_quote($key, '/') . '(?![A-Za-z0-9])/', $dict);
    }

    public static function extractName(string $dict, string $key): ?string
    {
        if (preg_match('/\/' . preg_quote($key, '/') . '(?![A-Za-z0-9])\s*\/(\w+)/', $dict, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * @return string[]|null array of "N G R" references, null if the key is absent
     */
    public static function extractRefArray(string $dict, string $key): ?array
    {
        if (!preg_match('/\/' . preg_quote($key, '/') . '(?![A-Za-z0-9])\s*\[(.*?)\]/s', $dict, $m)) {
            return null;
        }
        if (!preg_match_all('/(\d+)\s+(\d+)\s+R/', $m[1], $refs)) {
            return [];
        }
        $result = [];
        foreach ($refs[1] as $i => $number) {
            $result[] = $number . ' ' . $refs[2][$i] . ' R';
        }

        return $result;
    }

    /**
     * Extracts the content of a PDF dictionary correctly balancing any
     * nested sub-dictionaries (e.g. /Resources << /Font << ... >> >>):
     * unlike extractSubDict() (which stops at the first ">>" and therefore
     * truncates nested dictionaries), this method counts the depth.
     *
     * @param string $text text to search in (a parent dictionary, or the entire PDF)
     * @param int $contentStart position right after the opening "<<" of the dictionary to extract
     * @return array{0: string, 1: int} [content without delimiters, position of the closing ">>"]
     * @throws LocalizedException if the dictionary never closes
     */
    public static function extractBalancedDict(string $text, int $contentStart): array
    {
        $depth = 1;
        $pos = $contentStart;
        while ($depth > 0) {
            $nextOpen = strpos($text, '<<', $pos);
            $nextClose = strpos($text, '>>', $pos);
            if ($nextClose === false) {
                throw new LocalizedException(
                    __('Unsupported PDF: unbalanced dictionary (missing closing delimiter).')
                );
            }
            if ($nextOpen !== false && $nextOpen < $nextClose) {
                $depth++;
                $pos = $nextOpen + 2;
            } else {
                $depth--;
                $pos = $nextClose + 2;
            }
        }
        $closeStart = $pos - 2;

        return [substr($text, $contentStart, $closeStart - $contentStart), $closeStart];
    }
}
