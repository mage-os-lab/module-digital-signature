<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Pdf\Xref;

/**
 * Helper condivisi per costruire fixture binarie di xref stream nei test
 * (righe /W a larghezza variabile, big-endian).
 */
trait XrefStreamFixtureTrait
{
    private function encodeUint(int $value, int $width): string
    {
        $bytes = '';
        for ($i = $width - 1; $i >= 0; $i--) {
            $bytes .= chr(($value >> ($i * 8)) & 0xFF);
        }

        return $bytes;
    }

    /**
     * @param array<int, array{0: int, 1: int, 2: int}> $entries righe [type, field2, field3]
     */
    private function buildXrefStreamRows(array $entries, int $w1, int $w2, int $w3): string
    {
        $rows = '';
        foreach ($entries as [$type, $field2, $field3]) {
            $rows .= $this->encodeUint($type, $w1) . $this->encodeUint($field2, $w2) . $this->encodeUint($field3, $w3);
        }

        return $rows;
    }
}
