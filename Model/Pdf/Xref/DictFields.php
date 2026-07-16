<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Pdf\Xref;

use Magento\Framework\Exception\LocalizedException;

/**
 * Estrazione di campi da un dizionario PDF testuale già isolato come stringa
 * (es. il contenuto tra "<<" e ">>" di un oggetto). Solo lettura, nessuna
 * validazione strutturale completa: usato dai reader xref.
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
     * @return string[]|null array di riferimenti "N G R", null se la chiave è assente
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
     * Estrae il contenuto di un dizionario PDF bilanciando correttamente
     * eventuali sotto-dizionari annidati (es. /Resources << /Font << ... >> >>):
     * a differenza di extractSubDict() (che si ferma al primo ">>" e quindi
     * tronca dizionari con nesting), questo metodo conta la profondità.
     *
     * @param string $text testo in cui cercare (un dizionario padre, o l'intero PDF)
     * @param int $contentStart posizione subito dopo il "<<" di apertura del dizionario da estrarre
     * @return array{0: string, 1: int} [contenuto senza i delimitatori, posizione del "<< del ">>" di chiusura]
     * @throws LocalizedException se il dizionario non si chiude mai
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
                    __('PDF non supportato: dizionario non bilanciato (delimitatore di chiusura mancante).')
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
