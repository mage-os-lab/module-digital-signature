# Supporto xref stream (PDF 1.5+) in TagReplacer - Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Estendere `TagReplacer`/`TemplateValidator` per accettare e processare PDF template con cross-reference stream (PDF 1.5+), mantenendo zero dipendenze esterne, aggiungendo validazione upload come dry-run reale e un'anteprima scaricabile per il merchant.

**Architecture:** Nuovo namespace `Model/Pdf/Xref/` con un piccolo set di classi isolate (parsing dizionari, individuazione ultimo `startxref`, lettura tabella classica, lettura xref stream con decodifica PNG-predictor, risolutore di catena `/Prev` con profondità limitata) dietro cui `TagReplacer` e `TemplateValidator` restano quasi identici nella forma, cambiando solo la fonte di `Size`/`Root`/rilevamento object-stream/cifratura. Il path di lettura/scrittura per PDF classici (17 test esistenti) resta invariato byte per byte.

**Tech Stack:** PHP 8.1+, nessuna dipendenza Composer aggiuntiva (solo `ext-zlib` già presente), PHPUnit 10.5 standalone (stub minimi, niente Magento reale nei test unitari).

## Global Constraints

- Nessuna nuova dipendenza Composer o di sistema (vincolo di business: distribuzione su hosting condivisi non controllati).
- Fase 1: solo xref stream. PDF con **object stream** (compressione di più oggetti) e PDF **cifrati** (`/Encrypt`) restano esplicitamente non supportati e vengono rifiutati all'upload.
- Validazione all'upload = **dry-run reale** (stesso `TagReplacer` usato in produzione), non una checklist separata.
- Scrittura dell'incremental update: se la sorgente usa xref stream, il nuovo blocco appeso è anch'esso un xref stream (mai una tabella classica ibrida), scritto senza predictor/compressione (scelta nostra, a basso rischio).
- I 17 test unitari esistenti su `TagReplacer` e i test esistenti su `TemplateValidator` devono restare (o essere aggiornati in modo mirato e motivato) verdi: nessuna regressione silenziosa sul path PDF 1.4 classico.
- Ogni nuova eccezione utente-visibile (`LocalizedException`) va tradotta in tutte le lingue del modulo: `en_US`, `de_DE`, `es_ES`, `fr_FR`, `nl_NL`, `pt_BR`, `zh_Hans_CN` (il codice sorgente usa l'italiano come msgid, `it_IT.csv` non necessita voci identiche).
- Comando di test: `vendor/bin/phpunit` dalla root del repo (`/home/nino/PhpstormProjects/mage-os-module-firma-digitale`).

---

### Task 1: `Xref/DictFields` — estrazione campi da dizionario PDF testuale

**Files:**
- Create: `src/Model/Pdf/Xref/DictFields.php`
- Test: `src/Test/Unit/Model/Pdf/Xref/DictFieldsTest.php`

**Interfaces:**
- Produces: `DictFields::extractInt(string $dict, string $key): ?int`, `DictFields::extractIntArray(string $dict, string $key): ?array`, `DictFields::extractRef(string $dict, string $key): ?string`, `DictFields::extractSubDict(string $dict, string $key): ?string`, `DictFields::hasKey(string $dict, string $key): bool` — tutti metodi statici, usati dai task successivi (4, 5, 6).

- [x] **Step 1: Scrivere il test (fallirà: la classe non esiste)**

```php
<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Pdf\Xref;

use MageOS\DigitalSignature\Model\Pdf\Xref\DictFields;
use PHPUnit\Framework\TestCase;

class DictFieldsTest extends TestCase
{
    public function testExtractIntReadsValue(): void
    {
        self::assertSame(42, DictFields::extractInt('/Size 42 /Root 1 0 R', 'Size'));
    }

    public function testExtractIntReturnsNullWhenKeyAbsent(): void
    {
        self::assertNull(DictFields::extractInt('/Root 1 0 R', 'Size'));
    }

    public function testExtractIntDoesNotMatchPrefixOfLongerKey(): void
    {
        self::assertNull(DictFields::extractInt('/SizeXtra 42', 'Size'));
    }

    public function testExtractIntArrayReadsValues(): void
    {
        self::assertSame([1, 4, 2], DictFields::extractIntArray('/W [1 4 2] /Size 10', 'W'));
    }

    public function testExtractIntArrayReturnsNullWhenAbsent(): void
    {
        self::assertNull(DictFields::extractIntArray('/Size 10', 'Index'));
    }

    public function testExtractRefReadsIndirectReference(): void
    {
        self::assertSame('1 0 R', DictFields::extractRef('/Size 10 /Root 1 0 R', 'Root'));
    }

    public function testExtractSubDictReadsNestedDictionary(): void
    {
        self::assertSame(
            ' /Predictor 12 /Columns 5 ',
            DictFields::extractSubDict('/Filter /FlateDecode /DecodeParms << /Predictor 12 /Columns 5 >>', 'DecodeParms')
        );
    }

    public function testExtractSubDictReturnsNullWhenAbsent(): void
    {
        self::assertNull(DictFields::extractSubDict('/Size 10', 'DecodeParms'));
    }

    public function testHasKeyDetectsPresence(): void
    {
        self::assertTrue(DictFields::hasKey('/Size 10 /Encrypt 3 0 R', 'Encrypt'));
        self::assertFalse(DictFields::hasKey('/Size 10 /EncryptMetadata false', 'Encrypt'));
    }
}
```

- [x] **Step 2: Eseguire il test e verificare che fallisca**

Run: `vendor/bin/phpunit src/Test/Unit/Model/Pdf/Xref/DictFieldsTest.php`
Expected: FAIL (errore "Class DictFields not found")

- [x] **Step 3: Implementare `DictFields`**

```php
<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Pdf\Xref;

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
}
```

- [x] **Step 4: Eseguire il test e verificare che passi**

Run: `vendor/bin/phpunit src/Test/Unit/Model/Pdf/Xref/DictFieldsTest.php`
Expected: OK (10 test, tutti verdi)

- [x] **Step 5: Commit**

```bash
git add src/Model/Pdf/Xref/DictFields.php src/Test/Unit/Model/Pdf/Xref/DictFieldsTest.php
git commit -m "Aggiunge DictFields per estrazione campi da dizionari PDF"
```

---

### Task 2: `Xref/StartxrefLocator` — individuazione dell'ultimo startxref (estratto da TagReplacer)

**Files:**
- Create: `src/Model/Pdf/Xref/StartxrefLocator.php`
- Test: `src/Test/Unit/Model/Pdf/Xref/StartxrefLocatorTest.php`

**Interfaces:**
- Produces: `StartxrefLocator::locate(string $pdf): int` (throws `LocalizedException` se assente) — usato dal Task 6 (`XrefChainResolver`) e dal Task 7 (refactor `TagReplacer`).

- [x] **Step 1: Scrivere il test**

```php
<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Pdf\Xref;

use MageOS\DigitalSignature\Model\Pdf\Xref\StartxrefLocator;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class StartxrefLocatorTest extends TestCase
{
    public function testLocatesLastStartxref(): void
    {
        $pdf = "%PDF-1.4\n...\nstartxref\n123\n%%EOF\n...\nstartxref\n456\n%%EOF\n";

        self::assertSame(456, StartxrefLocator::locate($pdf));
    }

    public function testThrowsWhenMissing(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/startxref non trovato/');

        StartxrefLocator::locate("%PDF-1.4\nsenza xref\n");
    }
}
```

- [x] **Step 2: Eseguire il test e verificare che fallisca**

Run: `vendor/bin/phpunit src/Test/Unit/Model/Pdf/Xref/StartxrefLocatorTest.php`
Expected: FAIL (classe non esiste)

- [x] **Step 3: Implementare `StartxrefLocator`**

Logica identica al metodo privato `TagReplacer::findLastStartxref()` esistente (righe 223-231 del file attuale), solo estratta in classe statica riusabile.

```php
<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Pdf\Xref;

use Magento\Framework\Exception\LocalizedException;

/**
 * Individua l'ultimo "startxref" del file: punto di ingresso della catena di
 * cross-reference (tabella classica o stream) più recente.
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
            throw new LocalizedException(__('PDF non supportato: startxref non trovato.'));
        }

        return (int)end($matches[1]);
    }
}
```

- [x] **Step 4: Eseguire il test e verificare che passi**

Run: `vendor/bin/phpunit src/Test/Unit/Model/Pdf/Xref/StartxrefLocatorTest.php`
Expected: OK (2 test)

- [x] **Step 5: Commit**

```bash
git add src/Model/Pdf/Xref/StartxrefLocator.php src/Test/Unit/Model/Pdf/Xref/StartxrefLocatorTest.php
git commit -m "Aggiunge StartxrefLocator (estratto da TagReplacer)"
```

---

### Task 3: `Xref/ClassicXrefReader` — lettura tabella xref classica a un offset noto

**Files:**
- Create: `src/Model/Pdf/Xref/XrefLink.php`
- Create: `src/Model/Pdf/Xref/ClassicXrefReader.php`
- Test: `src/Test/Unit/Model/Pdf/Xref/ClassicXrefReaderTest.php`

**Interfaces:**
- Consumes: `DictFields::extractInt/extractRef/hasKey` (Task 1)
- Produces: `XrefLink` (proprietà pubbliche readonly: `size:int`, `root:string`, `hasEncrypt:bool`, `hasObjectStreams:bool`, `isStream:bool`, `prevOffset:?int`), `ClassicXrefReader::readAt(string $pdf, int $offset): XrefLink` — usato dal Task 6.

- [x] **Step 1: Scrivere il test**

```php
<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Pdf\Xref;

use MageOS\DigitalSignature\Model\Pdf\Xref\ClassicXrefReader;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class ClassicXrefReaderTest extends TestCase
{
    private ClassicXrefReader $reader;

    protected function setUp(): void
    {
        $this->reader = new ClassicXrefReader();
    }

    public function testReadsTrailerFields(): void
    {
        $pdf = "%PDF-1.4\n"
            . "xref\n0 1\n0000000000 65535 f \n"
            . "trailer\n<</Size 5 /Root 1 0 R>>\nstartxref\n9\n%%EOF\n";
        $offset = strpos($pdf, 'xref');

        $link = $this->reader->readAt($pdf, $offset);

        self::assertSame(5, $link->size);
        self::assertSame('1 0 R', $link->root);
        self::assertFalse($link->hasEncrypt);
        self::assertFalse($link->hasObjectStreams);
        self::assertFalse($link->isStream);
        self::assertNull($link->prevOffset);
    }

    public function testReadsPrevAndEncrypt(): void
    {
        $pdf = "xref\n0 1\n0000000000 65535 f \n"
            . "trailer\n<</Size 5 /Root 1 0 R /Prev 123 /Encrypt 3 0 R>>\nstartxref\n0\n%%EOF\n";

        $link = $this->reader->readAt($pdf, 0);

        self::assertSame(123, $link->prevOffset);
        self::assertTrue($link->hasEncrypt);
    }

    public function testThrowsWhenNotAtXrefKeyword(): void
    {
        $this->expectException(LocalizedException::class);

        $this->reader->readAt("%PDF-1.4\nnot xref here", 0);
    }

    public function testThrowsWhenTrailerMissingSizeOrRoot(): void
    {
        $pdf = "xref\n0 1\n0000000000 65535 f \ntrailer\n<</Root 1 0 R>>\n";

        $this->expectException(LocalizedException::class);

        $this->reader->readAt($pdf, 0);
    }
}
```

- [x] **Step 2: Eseguire il test e verificare che fallisca**

Run: `vendor/bin/phpunit src/Test/Unit/Model/Pdf/Xref/ClassicXrefReaderTest.php`
Expected: FAIL (classi non esistono)

- [x] **Step 3: Implementare `XrefLink`**

```php
<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Pdf\Xref;

/**
 * Esito della lettura di UNA sezione di cross-reference (tabella classica o
 * xref stream), prima di seguire l'eventuale catena /Prev.
 */
final class XrefLink
{
    public function __construct(
        public readonly int $size,
        public readonly string $root,
        public readonly bool $hasEncrypt,
        public readonly bool $hasObjectStreams,
        public readonly bool $isStream,
        public readonly ?int $prevOffset
    ) {
    }
}
```

- [x] **Step 4: Implementare `ClassicXrefReader`**

Riusa la stessa regex trailer già usata (e già testata) in `TagReplacer::extractTrailerDict()`, ma cercando a partire da un offset specifico (5° parametro di `preg_match`) invece che l'ultima occorrenza globale, per poter seguire una catena `/Prev`.

```php
<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Pdf\Xref;

use Magento\Framework\Exception\LocalizedException;

/**
 * Legge una sezione di cross-reference classica (tabella testuale + trailer)
 * a partire da un offset noto. Non risolve gli offset dei singoli oggetti
 * (TagReplacer li ritrova con una scansione diretta del testo): serve solo a
 * recuperare Size/Root/Encrypt/Prev del trailer associato.
 */
final class ClassicXrefReader
{
    /**
     * @throws LocalizedException
     */
    public function readAt(string $pdf, int $offset): XrefLink
    {
        if (substr($pdf, $offset, 4) !== 'xref') {
            throw new LocalizedException(
                __('PDF non supportato: sezione cross-reference classica non trovata alla posizione attesa.')
            );
        }
        if (!preg_match('/trailer\s*<<(.*?)>>/s', $pdf, $matches, 0, $offset)) {
            throw new LocalizedException(
                __('PDF non supportato: trailer non trovato dopo la tabella cross-reference.')
            );
        }
        $dict = $matches[1];
        $size = DictFields::extractInt($dict, 'Size');
        $root = DictFields::extractRef($dict, 'Root');
        if ($size === null || $root === null) {
            throw new LocalizedException(__('PDF non supportato: trailer privo di Size/Root.'));
        }

        return new XrefLink(
            size: $size,
            root: $root,
            hasEncrypt: DictFields::hasKey($dict, 'Encrypt'),
            hasObjectStreams: false,
            isStream: false,
            prevOffset: DictFields::extractInt($dict, 'Prev')
        );
    }
}
```

- [x] **Step 5: Eseguire il test e verificare che passi**

Run: `vendor/bin/phpunit src/Test/Unit/Model/Pdf/Xref/ClassicXrefReaderTest.php`
Expected: OK (4 test)

- [x] **Step 6: Commit**

```bash
git add src/Model/Pdf/Xref/XrefLink.php src/Model/Pdf/Xref/ClassicXrefReader.php src/Test/Unit/Model/Pdf/Xref/ClassicXrefReaderTest.php
git commit -m "Aggiunge ClassicXrefReader per lettura trailer a offset noto"
```

---

### Task 4: `Xref/XrefStreamReader` — lettura e decodifica xref stream (PDF 1.5+)

Il cuore tecnico del piano: parsing del dizionario dell'oggetto `/Type /XRef`, decodifica binaria delle righe secondo `/W`/`/Index`, e "un-predizione" PNG per lo stream (quasi tutti gli xref stream reali usano `/Predictor 12`, che segnala predizione PNG generica: ogni riga ha un byte di tipo filtro incorporato, non necessariamente sempre lo stesso).

**Files:**
- Create: `src/Test/Unit/Model/Pdf/Xref/XrefStreamFixtureTrait.php`
- Create: `src/Model/Pdf/Xref/XrefStreamReader.php`
- Test: `src/Test/Unit/Model/Pdf/Xref/XrefStreamReaderTest.php`

**Interfaces:**
- Consumes: `DictFields` (Task 1), `XrefLink` (Task 3)
- Produces: `XrefStreamReader::readAt(string $pdf, int $offset): XrefLink` — usato dal Task 6. `XrefStreamFixtureTrait` (helper di test riusato anche nei Task 5, 6, 7, 8).

- [x] **Step 1: Creare il trait di fixture condiviso per i test**

```php
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
```

- [x] **Step 2: Scrivere il test di `XrefStreamReader`**

```php
<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Pdf\Xref;

use MageOS\DigitalSignature\Model\Pdf\Xref\XrefStreamReader;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class XrefStreamReaderTest extends TestCase
{
    use XrefStreamFixtureTrait;

    private XrefStreamReader $reader;

    protected function setUp(): void
    {
        $this->reader = new XrefStreamReader();
    }

    /**
     * Applica il filtro PNG "Up" (tipo 2) riga per riga: ogni riga è
     * preceduta dal byte di tipo filtro e i byte sono la differenza rispetto
     * alla riga precedente (0 per la prima riga) - inverso esatto della
     * decodifica "Up" in XrefStreamReader.
     */
    private function applyPngUpFilter(string $rows, int $columns): string
    {
        $out = '';
        $prev = str_repeat("\x00", $columns);
        $rowCount = intdiv(strlen($rows), $columns);
        for ($r = 0; $r < $rowCount; $r++) {
            $row = substr($rows, $r * $columns, $columns);
            $out .= chr(2);
            for ($x = 0; $x < $columns; $x++) {
                $out .= chr((ord($row[$x]) - ord($prev[$x])) & 0xFF);
            }
            $prev = $row;
        }

        return $out;
    }

    private function buildXrefStreamPdf(string $dictExtra, string $streamData): string
    {
        return "%PDF-1.7\n"
            . "1 0 obj\n<<{$dictExtra} /Length " . strlen($streamData) . ">>\nstream\n"
            . $streamData . "\nendstream\nendobj\nstartxref\n9\n%%EOF\n";
    }

    public function testReadsUncompressedStreamWithoutFilter(): void
    {
        $rows = $this->buildXrefStreamRows([[1, 100, 0], [1, 200, 0]], 1, 4, 2);
        $pdf = $this->buildXrefStreamPdf('/Type /XRef /Size 3 /Root 2 0 R /W [1 4 2] /Index [1 2]', $rows);

        $link = $this->reader->readAt($pdf, strpos($pdf, '1 0 obj'));

        self::assertSame(3, $link->size);
        self::assertSame('2 0 R', $link->root);
        self::assertTrue($link->isStream);
        self::assertFalse($link->hasObjectStreams);
        self::assertFalse($link->hasEncrypt);
        self::assertNull($link->prevOffset);
    }

    public function testReadsFlateCompressedStreamWithPngUpPredictor(): void
    {
        $rows = $this->buildXrefStreamRows([[1, 100, 0], [1, 200, 0], [1, 300, 0]], 1, 4, 2);
        $predicted = $this->applyPngUpFilter($rows, 7); // colonne = somma(W) = 1+4+2
        $compressed = gzcompress($predicted, 9);
        $pdf = $this->buildXrefStreamPdf(
            '/Type /XRef /Size 4 /Root 2 0 R /W [1 4 2] /Index [1 3] '
            . '/Filter /FlateDecode /DecodeParms << /Predictor 12 /Columns 7 >>',
            $compressed
        );

        $link = $this->reader->readAt($pdf, strpos($pdf, '1 0 obj'));

        self::assertSame(4, $link->size);
        self::assertFalse($link->hasObjectStreams);
    }

    public function testDetectsObjectStreamEntries(): void
    {
        // type 2 = oggetto compresso dentro un object stream (num container, indice)
        $rows = $this->buildXrefStreamRows([[1, 100, 0], [2, 5, 0]], 1, 4, 2);
        $pdf = $this->buildXrefStreamPdf('/Type /XRef /Size 3 /Root 2 0 R /W [1 4 2] /Index [1 2]', $rows);

        $link = $this->reader->readAt($pdf, strpos($pdf, '1 0 obj'));

        self::assertTrue($link->hasObjectStreams);
    }

    public function testDetectsEncrypt(): void
    {
        $rows = $this->buildXrefStreamRows([[1, 100, 0]], 1, 4, 2);
        $pdf = $this->buildXrefStreamPdf(
            '/Type /XRef /Size 2 /Root 2 0 R /W [1 4 2] /Index [1 1] /Encrypt 5 0 R',
            $rows
        );

        $link = $this->reader->readAt($pdf, strpos($pdf, '1 0 obj'));

        self::assertTrue($link->hasEncrypt);
    }

    public function testReadsPrevOffset(): void
    {
        $rows = $this->buildXrefStreamRows([[1, 100, 0]], 1, 4, 2);
        $pdf = $this->buildXrefStreamPdf(
            '/Type /XRef /Size 2 /Root 2 0 R /W [1 4 2] /Index [1 1] /Prev 42',
            $rows
        );

        $link = $this->reader->readAt($pdf, strpos($pdf, '1 0 obj'));

        self::assertSame(42, $link->prevOffset);
    }

    public function testReadsMultipleIndexRanges(): void
    {
        $rows = $this->buildXrefStreamRows([[1, 100, 0], [1, 200, 0], [1, 300, 0]], 1, 4, 2);
        $pdf = $this->buildXrefStreamPdf('/Type /XRef /Size 10 /Root 2 0 R /W [1 4 2] /Index [1 1 5 2]', $rows);

        $link = $this->reader->readAt($pdf, strpos($pdf, '1 0 obj'));

        self::assertSame(10, $link->size);
        self::assertFalse($link->hasObjectStreams);
    }

    public function testRejectsUnsupportedPredictor(): void
    {
        $rows = $this->buildXrefStreamRows([[1, 100, 0]], 1, 4, 2);
        $compressed = gzcompress($rows, 9);
        $pdf = $this->buildXrefStreamPdf(
            '/Type /XRef /Size 2 /Root 2 0 R /W [1 4 2] /Index [1 1] '
            . '/Filter /FlateDecode /DecodeParms << /Predictor 2 /Columns 7 >>',
            $compressed
        );

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/predictor/');

        $this->reader->readAt($pdf, strpos($pdf, '1 0 obj'));
    }

    public function testThrowsWhenNotAnXRefObject(): void
    {
        $pdf = "1 0 obj\n<</Type /Catalog /Length 0>>\nstream\n\nendstream\nendobj\n";

        $this->expectException(LocalizedException::class);

        $this->reader->readAt($pdf, 0);
    }
}
```

- [x] **Step 3: Eseguire il test e verificare che fallisca**

Run: `vendor/bin/phpunit src/Test/Unit/Model/Pdf/Xref/XrefStreamReaderTest.php`
Expected: FAIL (classe non esiste)

- [x] **Step 4: Implementare `XrefStreamReader`**

```php
<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Pdf\Xref;

use Magento\Framework\Exception\LocalizedException;

/**
 * Legge un oggetto cross-reference stream (PDF 1.5+, /Type /XRef): decodifica
 * il dizionario, decomprime lo stream (FlateDecode + eventuale PNG predictor)
 * e interpreta le righe binarie secondo /W e /Index. Supporta il caso
 * Colors=1/BitsPerComponent=8 (di gran lunga il più comune per gli xref
 * stream) e predictor 1 (nessuno) o 10-15 (PNG generico: ogni riga porta un
 * proprio byte di tipo filtro, letto ed applicato individualmente).
 */
final class XrefStreamReader
{
    /**
     * Cap alla dimensione decompressa dello stream, stessa difesa
     * anti "decompression bomb" già usata in TagReplacer.
     */
    private const MAX_DECOMPRESSED_STREAM = 52428800;

    /**
     * @throws LocalizedException
     */
    public function readAt(string $pdf, int $offset): XrefLink
    {
        $region = substr($pdf, $offset);
        if (!preg_match('/^(\d+)\s+(\d+)\s+obj\s*<</', $region, $header)) {
            throw new LocalizedException(__('PDF non supportato: oggetto cross-reference stream non riconosciuto.'));
        }
        if (!preg_match('/>>\s*stream(\r\n|\n)/', $region, $streamMatch, PREG_OFFSET_CAPTURE)) {
            throw new LocalizedException(__('PDF non supportato: stream cross-reference non riconosciuto.'));
        }
        $dictStart = strlen($header[0]);
        $dictEnd = $streamMatch[0][1];
        $dict = substr($region, $dictStart, $dictEnd - $dictStart);

        $dataStart = $streamMatch[0][1] + strlen($streamMatch[0][0]);
        $endstreamPos = strpos($region, 'endstream', $dataStart);
        if ($endstreamPos === false) {
            throw new LocalizedException(__('PDF non supportato: stream cross-reference incompleto.'));
        }
        $dataEnd = $endstreamPos;
        if (substr($region, $dataEnd - 1, 1) === "\n") {
            $dataEnd--;
            if (substr($region, $dataEnd - 1, 1) === "\r") {
                $dataEnd--;
            }
        }
        $raw = substr($region, $dataStart, $dataEnd - $dataStart);

        if (!str_contains($dict, '/XRef')) {
            throw new LocalizedException(__('PDF non supportato: atteso un oggetto /Type /XRef.'));
        }

        $size = DictFields::extractInt($dict, 'Size');
        $root = DictFields::extractRef($dict, 'Root');
        $widths = DictFields::extractIntArray($dict, 'W');
        if ($size === null || $root === null || $widths === null || count($widths) !== 3) {
            throw new LocalizedException(__('PDF non supportato: dizionario cross-reference stream incompleto.'));
        }
        $index = DictFields::extractIntArray($dict, 'Index') ?? [0, $size];

        $content = $this->decodeStreamData($raw, $dict);
        $rows = $this->decodeRows($content, $widths, $index);

        $hasObjectStreams = false;
        foreach ($rows as $row) {
            if ($row[0] === 2) {
                $hasObjectStreams = true;
                break;
            }
        }

        return new XrefLink(
            size: $size,
            root: $root,
            hasEncrypt: DictFields::hasKey($dict, 'Encrypt'),
            hasObjectStreams: $hasObjectStreams,
            isStream: true,
            prevOffset: DictFields::extractInt($dict, 'Prev')
        );
    }

    /**
     * @throws LocalizedException
     */
    private function decodeStreamData(string $raw, string $dict): string
    {
        if (!str_contains($dict, '/FlateDecode')) {
            return $raw;
        }
        // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- warning atteso oltre il cap anti-bomba
        $content = @gzuncompress($raw, self::MAX_DECOMPRESSED_STREAM);
        if ($content === false) {
            throw new LocalizedException(__('PDF non supportato: stream cross-reference non decomprimibile.'));
        }

        $decodeParms = DictFields::extractSubDict($dict, 'DecodeParms') ?? DictFields::extractSubDict($dict, 'DP') ?? '';
        $predictor = DictFields::extractInt($decodeParms, 'Predictor') ?? 1;
        if ($predictor === 1) {
            return $content;
        }
        if ($predictor < 10) {
            throw new LocalizedException(
                __('PDF non supportato: predictor cross-reference stream non gestito (valore %1).', $predictor)
            );
        }
        $columns = DictFields::extractInt($decodeParms, 'Columns');
        $colors = DictFields::extractInt($decodeParms, 'Colors') ?? 1;
        $bpc = DictFields::extractInt($decodeParms, 'BitsPerComponent') ?? 8;
        if ($columns === null || $colors !== 1 || $bpc !== 8) {
            throw new LocalizedException(
                __('PDF non supportato: parametri di predizione cross-reference stream non gestiti.')
            );
        }

        return $this->undoPngPrediction($content, $columns);
    }

    /**
     * @param int[] $widths [w1, w2, w3]
     * @param int[] $index coppie [obj_start, count, obj_start, count, ...]
     * @return array<int, array{0: int, 1: int, 2: int}>
     * @throws LocalizedException
     */
    private function decodeRows(string $content, array $widths, array $index): array
    {
        [$w1, $w2, $w3] = $widths;
        $rowWidth = $w1 + $w2 + $w3;
        if ($rowWidth <= 0) {
            throw new LocalizedException(__('PDF non supportato: larghezze /W cross-reference stream non valide.'));
        }
        $entryCount = 0;
        for ($i = 0; $i < count($index); $i += 2) {
            $entryCount += $index[$i + 1];
        }
        if (strlen($content) < $rowWidth * $entryCount) {
            throw new LocalizedException(
                __('PDF non supportato: stream cross-reference troppo corto per le voci dichiarate.')
            );
        }

        $rows = [];
        $pos = 0;
        for ($i = 0; $i < $entryCount; $i++) {
            $row = substr($content, $pos, $rowWidth);
            $type = $w1 === 0 ? 1 : $this->decodeField($row, 0, $w1);
            $field2 = $this->decodeField($row, $w1, $w2);
            $field3 = $this->decodeField($row, $w1 + $w2, $w3);
            $rows[] = [$type, $field2, $field3];
            $pos += $rowWidth;
        }

        return $rows;
    }

    private function decodeField(string $bytes, int $offset, int $width): int
    {
        if ($width === 0) {
            return 0;
        }
        $value = 0;
        for ($i = 0; $i < $width; $i++) {
            $value = ($value << 8) | ord($bytes[$offset + $i]);
        }

        return $value;
    }

    /**
     * @throws LocalizedException
     */
    private function undoPngPrediction(string $content, int $columns): string
    {
        $rowLength = $columns + 1;
        if ($rowLength <= 1 || strlen($content) % $rowLength !== 0) {
            throw new LocalizedException(
                __('PDF non supportato: stream cross-reference con predizione PNG malformato.')
            );
        }
        $rowCount = intdiv(strlen($content), $rowLength);

        $out = '';
        $prev = str_repeat("\x00", $columns);
        for ($r = 0; $r < $rowCount; $r++) {
            $rowStart = $r * $rowLength;
            $filterType = ord($content[$rowStart]);
            $raw = substr($content, $rowStart + 1, $columns);
            $recon = '';
            for ($x = 0; $x < $columns; $x++) {
                $rawByte = ord($raw[$x]);
                $left = $x > 0 ? ord($recon[$x - 1]) : 0;
                $up = ord($prev[$x]);
                $upLeft = $x > 0 ? ord($prev[$x - 1]) : 0;
                $value = match ($filterType) {
                    0 => $rawByte,
                    1 => $rawByte + $left,
                    2 => $rawByte + $up,
                    3 => $rawByte + intdiv($left + $up, 2),
                    4 => $rawByte + $this->paethPredictor($left, $up, $upLeft),
                    default => throw new LocalizedException(
                        __(
                            'PDF non supportato: filtro PNG riga cross-reference stream non riconosciuto (%1).',
                            $filterType
                        )
                    ),
                };
                $recon .= chr($value & 0xFF);
            }
            $out .= $recon;
            $prev = $recon;
        }

        return $out;
    }

    private function paethPredictor(int $left, int $up, int $upLeft): int
    {
        $p = $left + $up - $upLeft;
        $predLeft = abs($p - $left);
        $predUp = abs($p - $up);
        $predUpLeft = abs($p - $upLeft);
        if ($predLeft <= $predUp && $predLeft <= $predUpLeft) {
            return $left;
        }
        if ($predUp <= $predUpLeft) {
            return $up;
        }

        return $upLeft;
    }
}
```

- [x] **Step 5: Eseguire il test e verificare che passi**

Run: `vendor/bin/phpunit src/Test/Unit/Model/Pdf/Xref/XrefStreamReaderTest.php`
Expected: OK (8 test)

- [x] **Step 6: Commit**

```bash
git add src/Model/Pdf/Xref/XrefStreamReader.php src/Test/Unit/Model/Pdf/Xref/XrefStreamReaderTest.php src/Test/Unit/Model/Pdf/Xref/XrefStreamFixtureTrait.php
git commit -m "Aggiunge XrefStreamReader: lettura xref stream PDF 1.5+ con un-predizione PNG"
```

---

### Task 5: `Xref/XrefChainResolver` — orchestrazione classica/stream + catena /Prev limitata

**Files:**
- Create: `src/Model/Pdf/Xref/XrefInfo.php`
- Create: `src/Model/Pdf/Xref/XrefChainResolver.php`
- Test: `src/Test/Unit/Model/Pdf/Xref/XrefChainResolverTest.php`

**Interfaces:**
- Consumes: `StartxrefLocator::locate()` (Task 2), `ClassicXrefReader::readAt()` (Task 3), `XrefStreamReader::readAt()` (Task 4), `XrefStreamFixtureTrait` (Task 4, per i test)
- Produces: `XrefInfo` (proprietà readonly: `size:int`, `root:string`, `hasObjectStreams:bool`, `isEncrypted:bool`, `isStreamBased:bool`), `XrefChainResolver::resolve(string $pdf): XrefInfo` — punto di ingresso unico usato dai Task 7 e 8.

- [x] **Step 1: Scrivere il test**

```php
<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Pdf\Xref;

use MageOS\DigitalSignature\Model\Pdf\Xref\ClassicXrefReader;
use MageOS\DigitalSignature\Model\Pdf\Xref\XrefChainResolver;
use MageOS\DigitalSignature\Model\Pdf\Xref\XrefStreamReader;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class XrefChainResolverTest extends TestCase
{
    use XrefStreamFixtureTrait;

    private XrefChainResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new XrefChainResolver(new ClassicXrefReader(), new XrefStreamReader());
    }

    public function testResolvesClassicOnlyFile(): void
    {
        $pdf = "%PDF-1.4\n"
            . "xref\n0 1\n0000000000 65535 f \n"
            . "trailer\n<</Size 5 /Root 1 0 R>>\nstartxref\n9\n%%EOF\n";

        $info = $this->resolver->resolve($pdf);

        self::assertSame(5, $info->size);
        self::assertSame('1 0 R', $info->root);
        self::assertFalse($info->isStreamBased);
        self::assertFalse($info->hasObjectStreams);
        self::assertFalse($info->isEncrypted);
    }

    public function testResolvesStreamOnlyFile(): void
    {
        $rows = $this->buildXrefStreamRows([[1, 100, 0]], 1, 4, 2);
        $header = "%PDF-1.7\n";
        $body = "1 0 obj\n<</Type /XRef /Size 2 /Root 2 0 R /W [1 4 2] /Index [1 1]"
            . ' /Length ' . strlen($rows) . ">>\nstream\n" . $rows . "\nendstream\nendobj\n";
        $offset = strlen($header);
        $pdf = $header . $body . "startxref\n{$offset}\n%%EOF\n";

        self::assertSame($offset, strpos($pdf, '1 0 obj'));

        $info = $this->resolver->resolve($pdf);

        self::assertSame(2, $info->size);
        self::assertTrue($info->isStreamBased);
    }

    public function testAggregatesObjectStreamsFromCurrentRevision(): void
    {
        $rows = $this->buildXrefStreamRows([[2, 5, 0]], 1, 4, 2);
        $classicSection = "xref\n0 1\n0000000000 65535 f \ntrailer\n<</Size 3 /Root 1 0 R>>\n";
        $streamOffset = strlen($classicSection);
        $pdf = $classicSection
            . '1 0 obj' . "\n<</Type /XRef /Size 4 /Root 2 0 R /W [1 4 2] /Index [1 1] /Prev 0"
            . ' /Length ' . strlen($rows) . ">>\nstream\n" . $rows . "\nendstream\nendobj\n"
            . "startxref\n{$streamOffset}\n%%EOF\n";

        $info = $this->resolver->resolve($pdf);

        self::assertTrue($info->hasObjectStreams);
        self::assertTrue($info->isStreamBased);
    }

    public function testRejectsChainLongerThanLimit(): void
    {
        $link1 = "xref\n0 1\n0000000000 65535 f \ntrailer\n<</Size 1 /Root 1 0 R>>\n";
        $link2Offset = strlen($link1);
        $link2 = "xref\n0 1\n0000000000 65535 f \ntrailer\n<</Size 2 /Root 1 0 R /Prev 0>>\n";
        $link3Offset = $link2Offset + strlen($link2);
        $link3 = "xref\n0 1\n0000000000 65535 f \ntrailer\n<</Size 3 /Root 1 0 R /Prev {$link2Offset}>>\n";
        $pdf = $link1 . $link2 . $link3 . "startxref\n{$link3Offset}\n%%EOF\n";

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/troppe revisioni/');

        $this->resolver->resolve($pdf);
    }
}
```

- [x] **Step 2: Eseguire il test e verificare che fallisca**

Run: `vendor/bin/phpunit src/Test/Unit/Model/Pdf/Xref/XrefChainResolverTest.php`
Expected: FAIL (classi non esistono)

- [x] **Step 3: Implementare `XrefInfo`**

```php
<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Pdf\Xref;

/**
 * Esito della risoluzione dell'intera catena di cross-reference di un PDF
 * (tabella/stream più recente + storico entro il limite supportato).
 */
final class XrefInfo
{
    public function __construct(
        public readonly int $size,
        public readonly string $root,
        public readonly bool $hasObjectStreams,
        public readonly bool $isEncrypted,
        public readonly bool $isStreamBased
    ) {
    }
}
```

- [x] **Step 4: Implementare `XrefChainResolver`**

```php
<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Pdf\Xref;

use Magento\Framework\Exception\LocalizedException;

/**
 * Risolve la catena di cross-reference di un PDF (tabella classica e/o xref
 * stream, anche mista), seguendo /Prev fino a un limite fissato. Aggrega
 * hasObjectStreams/isEncrypted su tutta la catena esplorata; Size/Root/
 * isStreamBased vengono dalla revisione più recente (quella da cui parte un
 * eventuale nuovo incremental update).
 */
final class XrefChainResolver
{
    /**
     * Limite di profondità della catena /Prev esplorata: oltre, si rifiuta
     * esplicitamente invece di ignorare silenziosamente revisioni storiche
     * (potrebbero contenere object stream non rilevati). Copre il caso comune
     * (file appena esportato, 0 o 1 revisione precedente).
     */
    private const MAX_CHAIN_DEPTH = 2;

    public function __construct(
        private readonly ClassicXrefReader $classicReader,
        private readonly XrefStreamReader $streamReader
    ) {
    }

    /**
     * @throws LocalizedException
     */
    public function resolve(string $pdf): XrefInfo
    {
        $offset = StartxrefLocator::locate($pdf);
        $current = $this->readLinkAt($pdf, $offset);

        $hasObjectStreams = $current->hasObjectStreams;
        $hasEncrypt = $current->hasEncrypt;
        $prevOffset = $current->prevOffset;
        $depth = 1;
        while ($prevOffset !== null) {
            if ($depth >= self::MAX_CHAIN_DEPTH) {
                throw new LocalizedException(__(
                    'PDF non supportato: catena cross-reference con troppe revisioni collegate (max %1).',
                    self::MAX_CHAIN_DEPTH
                ));
            }
            $link = $this->readLinkAt($pdf, $prevOffset);
            $hasObjectStreams = $hasObjectStreams || $link->hasObjectStreams;
            $hasEncrypt = $hasEncrypt || $link->hasEncrypt;
            $prevOffset = $link->prevOffset;
            $depth++;
        }

        return new XrefInfo(
            size: $current->size,
            root: $current->root,
            hasObjectStreams: $hasObjectStreams,
            isEncrypted: $hasEncrypt,
            isStreamBased: $current->isStream
        );
    }

    private function readLinkAt(string $pdf, int $offset): XrefLink
    {
        return substr($pdf, $offset, 4) === 'xref'
            ? $this->classicReader->readAt($pdf, $offset)
            : $this->streamReader->readAt($pdf, $offset);
    }
}
```

- [x] **Step 5: Eseguire il test e verificare che passi**

Run: `vendor/bin/phpunit src/Test/Unit/Model/Pdf/Xref/XrefChainResolverTest.php`
Expected: OK (4 test)

- [x] **Step 6: Commit**

```bash
git add src/Model/Pdf/Xref/XrefInfo.php src/Model/Pdf/Xref/XrefChainResolver.php src/Test/Unit/Model/Pdf/Xref/XrefChainResolverTest.php
git commit -m "Aggiunge XrefChainResolver: risoluzione catena xref classica/stream"
```

---

### Task 6: Refactor `TagReplacer` — consumo di `XrefChainResolver`, scrittura xref stream

**Files:**
- Modify: `src/Model/Pdf/TagReplacer.php` (intero file)
- Modify: `src/Test/Unit/Model/Pdf/TagReplacerTest.php`

**Interfaces:**
- Consumes: `XrefChainResolver::resolve()` (Task 5), `StartxrefLocator::locate()` (Task 2)
- Produces: `TagReplacer::__construct(XrefChainResolver $xrefResolver)` (nuova firma) — il Task 7 (`TemplateValidator`) e il Task 8 (`Controller/Adminhtml/Template/Preview`) dipendono da questa firma per l'istanziazione (auto-wiring Magento, nessuna modifica a `di.xml` richiesta).

- [x] **Step 1: Aggiornare `TagReplacerTest`: nuova firma del costruttore + fix messaggio + nuovo test xref-stream**

Sostituire l'intero contenuto di `src/Test/Unit/Model/Pdf/TagReplacerTest.php` con:

```php
<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Pdf;

use MageOS\DigitalSignature\Model\Pdf\TagReplacer;
use MageOS\DigitalSignature\Model\Pdf\Xref\ClassicXrefReader;
use MageOS\DigitalSignature\Model\Pdf\Xref\XrefChainResolver;
use MageOS\DigitalSignature\Model\Pdf\Xref\XrefStreamReader;
use MageOS\DigitalSignature\Test\Unit\Model\Pdf\Xref\XrefStreamFixtureTrait;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class TagReplacerTest extends TestCase
{
    use XrefStreamFixtureTrait;

    private const TAG = '{WSIGN#80,20#placeholder@example.com}';

    private TagReplacer $tagReplacer;

    protected function setUp(): void
    {
        $this->tagReplacer = new TagReplacer(
            new XrefChainResolver(new ClassicXrefReader(), new XrefStreamReader())
        );
    }

    public function testFindTagsInPlainStream(): void
    {
        $pdf = $this->buildPdf('BT (' . self::TAG . ') Tj ET');

        self::assertSame([self::TAG], $this->tagReplacer->findTags($pdf));
    }

    public function testFindTagsInCompressedStream(): void
    {
        $pdf = $this->buildPdf('BT (' . self::TAG . ') Tj ET', true);

        self::assertSame([self::TAG], $this->tagReplacer->findTags($pdf));
    }

    public function testFindTagsReturnsEmptyWhenNoTagPresent(): void
    {
        $pdf = $this->buildPdf('BT (documento senza tag) Tj ET');

        self::assertSame([], $this->tagReplacer->findTags($pdf));
    }

    public function testFindTagsDetectsTagOutsideStreams(): void
    {
        $pdf = "%PDF-1.4\n" . self::TAG . "\ntrailer\n<</Size 1 /Root 1 0 R>>\nstartxref\n9\n%%EOF\n";

        self::assertSame([self::TAG], $this->tagReplacer->findTags($pdf));
    }

    public function testFindTagsDeduplicates(): void
    {
        $content = 'BT (' . self::TAG . ') Tj (' . self::TAG . ') Tj ET';
        $pdf = $this->buildPdf($content);

        self::assertSame([self::TAG], $this->tagReplacer->findTags($pdf));
    }

    public function testReplaceSignerEmailInPlainStream(): void
    {
        $pdf = $this->buildPdf('BT (' . self::TAG . ') Tj ET');

        $result = $this->tagReplacer->replaceSignerEmail($pdf, 'cliente.reale@example.com');

        self::assertStringStartsWith('%PDF', $result);
        // Il placeholder non deve più esistere da nessuna parte (oggetto sbiancato)
        self::assertStringNotContainsString('placeholder@example.com', $result);
        // Round-trip: il tag aggiornato è leggibile con lo stesso parser
        self::assertSame(
            ['{WSIGN#80,20#cliente.reale@example.com}'],
            $this->tagReplacer->findTags($result)
        );
        // Incremental update: nuova xref collegata alla precedente
        self::assertStringContainsString('/Prev', $result);
        self::assertSame(2, substr_count($result, 'startxref'));
    }

    public function testReplaceSignerEmailInCompressedStream(): void
    {
        $pdf = $this->buildPdf('BT (' . self::TAG . ') Tj ET', true);

        $result = $this->tagReplacer->replaceSignerEmail($pdf, 'cliente.reale@example.com');

        self::assertSame(
            ['{WSIGN#80,20#cliente.reale@example.com}'],
            $this->tagReplacer->findTags($result)
        );
    }

    public function testReplaceEscapesPdfStringDelimiters(): void
    {
        $pdf = $this->buildPdf('BT (' . self::TAG . ') Tj ET');

        $result = $this->tagReplacer->replaceSignerEmail($pdf, 'a(b)@example.com');

        // ( e ) sono delimitatori di stringa PDF: devono uscire escapati
        self::assertStringContainsString('{WSIGN#80,20#a\\(b\\)@example.com}', $result);
    }

    public function testReplaceThrowsOnNonPdfContent(): void
    {
        $this->expectException(LocalizedException::class);

        $this->tagReplacer->replaceSignerEmail('non sono un pdf', 'a@b.it');
    }

    public function testReplaceThrowsWhenNoTagPresent(): void
    {
        $pdf = $this->buildPdf('BT (documento senza tag) Tj ET');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/Nessun tag firma/');

        $this->tagReplacer->replaceSignerEmail($pdf, 'a@b.it');
    }

    public function testStreamUnderDecompressionCapIsParsed(): void
    {
        // Stream FlateDecode legittimo (contenuto piccolo): il tag si legge.
        $pdf = $this->buildPdf('BT (' . self::TAG . ') Tj ET', true);

        self::assertSame([self::TAG], $this->tagReplacer->findTags($pdf));
    }

    public function testDecompressionBombOverCapIsRejected(): void
    {
        // Stream con un tag valido seguito da ~60 MB di padding: superato il cap
        // anti-bomba, gzuncompress fallisce e l'intero oggetto viene scartato.
        // Preferiamo perdere un tag piuttosto che decomprimere uno stream ostile:
        // il risultato è "nessun tag" (senza il cap il tag verrebbe invece letto).
        $content = self::TAG . str_repeat('A', 60 * 1024 * 1024);
        $compressed = gzcompress($content, 9);
        unset($content);
        $pdf = "%PDF-1.4\n1 0 obj\n<</Filter /FlateDecode /Length " . strlen($compressed) . ">>\n"
            . "stream\n" . $compressed . "\nendstream\nendobj\n"
            . "trailer\n<</Size 2 /Root 1 0 R>>\nstartxref\n9\n%%EOF\n";

        self::assertSame(
            [],
            $this->tagReplacer->findTags($pdf),
            'Lo stream oltre il cap deve essere scartato, non decompresso'
        );
    }

    public function testPathologicalInputsDoNotHang(): void
    {
        // Regressione ReDoS: sequenze lunghe non devono degenerare in backtracking
        $start = microtime(true);
        $this->tagReplacer->findTags('%PDF-1.4\n{WSIGN#' . str_repeat('1,', 50000) . '#a@b.it');
        $this->tagReplacer->findTags('%PDF-1.4\n{WSIGN#1,1#' . str_repeat('x', 200000));
        self::assertLessThan(2.0, microtime(true) - $start, 'Il parsing non deve degenerare');
    }

    public function testReplaceThrowsWhenNoXrefRecognized(): void
    {
        // PDF con tag ma senza alcuna struttura cross-reference riconoscibile
        // alla posizione indicata da startxref (né tabella classica, né un
        // oggetto /Type /XRef valido).
        $content = 'BT (' . self::TAG . ') Tj ET';
        $pdf = "%PDF-1.5\n4 0 obj\n<</Length " . strlen($content) . ">>\nstream\n"
            . $content . "\nendstream\nendobj\nstartxref\n9\n%%EOF\n";

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/XRef/');

        $this->tagReplacer->replaceSignerEmail($pdf, 'a@b.it');
    }

    public function testReplaceSignerEmailOnXrefStreamSource(): void
    {
        $header = "%PDF-1.7\n";
        $content = 'BT (' . self::TAG . ') Tj ET';
        $obj1Offset = strlen($header);
        $obj1 = "1 0 obj\n<</Length " . strlen($content) . ">>\nstream\n{$content}\nendstream\nendobj\n";
        $xrefOffset = strlen($header . $obj1);

        $rows = $this->buildXrefStreamRows(
            [[1, $obj1Offset, 0], [1, $xrefOffset, 0]],
            1,
            4,
            2
        );
        $xrefObj = "2 0 obj\n<</Type /XRef /Size 3 /Root 1 0 R /W [1 4 2] /Index [1 2]"
            . ' /Length ' . strlen($rows) . ">>\nstream\n{$rows}\nendstream\nendobj\n";

        $pdf = $header . $obj1 . $xrefObj . "startxref\n{$xrefOffset}\n%%EOF\n";

        $result = $this->tagReplacer->replaceSignerEmail($pdf, 'cliente.reale@example.com');

        self::assertStringNotContainsString('placeholder@example.com', $result);
        self::assertSame(
            ['{WSIGN#80,20#cliente.reale@example.com}'],
            $this->tagReplacer->findTags($result)
        );
        self::assertStringContainsString('/Type /XRef', $result);
        self::assertSame(2, substr_count($result, 'startxref'));
    }

    /**
     * PDF 1.4 minimo con un content stream e trailer classico. Gli offset
     * della xref sono fittizi: TagReplacer usa solo startxref e trailer.
     */
    private function buildPdf(string $streamContent, bool $compressed = false): string
    {
        $data = $compressed ? gzcompress($streamContent, 9) : $streamContent;
        $dict = $compressed
            ? sprintf('<</Filter /FlateDecode /Length %d>>', strlen($data))
            : sprintf('<</Length %d>>', strlen($data));

        $pdf = "%PDF-1.4\n";
        $pdf .= "1 0 obj\n<</Type /Catalog /Pages 2 0 R>>\nendobj\n";
        $pdf .= "2 0 obj\n<</Type /Pages /Kids [3 0 R] /Count 1>>\nendobj\n";
        $pdf .= "3 0 obj\n<</Type /Page /Parent 2 0 R /Contents 4 0 R>>\nendobj\n";
        $pdf .= "4 0 obj\n{$dict}\nstream\n{$data}\nendstream\nendobj\n";
        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 5\n0000000000 65535 f \n" . str_repeat("0000000000 00000 n \n", 4);
        $pdf .= "trailer\n<</Size 5 /Root 1 0 R>>\nstartxref\n{$xrefPos}\n%%EOF\n";

        return $pdf;
    }
}
```

Nota sulle modifiche rispetto al file esistente: `setUp()` ora costruisce `TagReplacer` con `XrefChainResolver`; il test `testReplaceThrowsWithoutClassicTrailer` è stato rinominato `testReplaceThrowsWhenNoXrefRecognized` con un'asserzione sul messaggio aggiornata (`/XRef/` invece di `/cross-reference/`, perché con il nuovo resolver quella fixture — un oggetto generico senza xref valida — fallisce nel riconoscere un oggetto `/Type /XRef`, non più genericamente "cross-reference table classica non trovata"); è stato aggiunto `testReplaceSignerEmailOnXrefStreamSource` per il nuovo path.

- [x] **Step 2: Eseguire i test e verificare che falliscano (costruttore non ancora aggiornato)**

Run: `vendor/bin/phpunit src/Test/Unit/Model/Pdf/TagReplacerTest.php`
Expected: FAIL (errore di argomenti nel costruttore `TagReplacer`)

- [x] **Step 3: Aggiornare `TagReplacer`**

Sostituire l'intero contenuto di `src/Model/Pdf/TagReplacer.php` con:

```php
<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Pdf;

use MageOS\DigitalSignature\Model\Pdf\Xref\StartxrefLocator;
use MageOS\DigitalSignature\Model\Pdf\Xref\XrefChainResolver;
use MageOS\DigitalSignature\Model\Pdf\Xref\XrefInfo;
use Magento\Framework\Exception\LocalizedException;

/**
 * Individua i tag firma {WSIGN#W,H#email} nel PDF e sostituisce l'email
 * placeholder con quella reale, riscrivendo il content stream tramite
 * "incremental update" (appende gli oggetti modificati + delta xref, come
 * previsto dallo standard PDF). Pure PHP, nessuna dipendenza esterna.
 *
 * Supporta PDF con cross-reference table classica (PDF 1.4) e con xref
 * stream (PDF 1.5+, senza object stream): l'incremental update scritto usa
 * lo stesso formato della revisione più recente del PDF sorgente.
 */
class TagReplacer
{
    public const TAG_REGEX = '/\{WSIGN#[0-9.,]+#([^}]*)\}/';

    /**
     * Cap alla dimensione decompressa di un singolo stream FlateDecode.
     * Difesa contro le "decompression bomb": uno stream di pochi KB può
     * espandersi a centinaia di MB (amplificazione zlib), aggirando il cap
     * sulla dimensione del file. 50 MB è ampio per stream legittimi (anche
     * immagini) ma taglia l'amplificazione patologica.
     */
    private const MAX_DECOMPRESSED_STREAM = 52428800;

    public function __construct(private readonly XrefChainResolver $xrefResolver)
    {
    }

    /**
     * Tag trovati nel PDF (in qualunque stream, anche compresso).
     *
     * @return string[] tag completi, es. ["{WSIGN#80,20#placeholder@example.com}"]
     */
    public function findTags(string $pdf): array
    {
        $tags = [];
        foreach ($this->extractStreamObjects($pdf) as $object) {
            if (preg_match_all(self::TAG_REGEX, $object['content'], $matches)) {
                array_push($tags, ...$matches[0]);
            }
        }
        // Tag eventualmente fuori dagli stream (PDF non strutturati)
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
     * Sostituisce l'email placeholder di tutti i tag con quella reale.
     *
     * Strategia ibrida: l'oggetto aggiornato viene appeso con una nuova sezione
     * xref (incremental update) e i byte dell'oggetto originale vengono
     * sbiancati in place con spazi della STESSA lunghezza — gli offset della
     * xref esistente restano validi e il tag placeholder non è più presente
     * nel file, nemmeno per scanner testuali non conformi allo standard.
     *
     * @throws LocalizedException se nessun tag è presente o il PDF non è supportato
     */
    public function replaceSignerEmail(string $pdf, string $email): string
    {
        if (!str_starts_with($pdf, '%PDF')) {
            throw new LocalizedException(__('Il file template non è un PDF.'));
        }
        // L'email finisce dentro una stringa letterale PDF: escape dei caratteri riservati
        $escapedEmail = addcslashes($email, "\\()");
        $replacer = static function (array $m) use ($escapedEmail): string {
            // Ricostruzione per concatenazione: nessun problema di escaping
            // della replacement string di preg_replace
            $lastHash = strrpos($m[0], '#');

            return substr($m[0], 0, $lastHash) . '#' . $escapedEmail . '}';
        };

        $modifiedObjects = [];
        foreach ($this->extractStreamObjects($pdf) as $object) {
            if (!preg_match(self::TAG_REGEX, $object['content'])) {
                continue;
            }
            $newContent = preg_replace_callback(self::TAG_REGEX, $replacer, $object['content']);
            $modifiedObjects[] = [
                'number' => $object['number'],
                'body' => $this->buildStreamObject($object['number'], $newContent, $object['compressed']),
            ];
            // Sbianca i byte dell'oggetto originale preservandone la lunghezza
            $pdf = substr_replace($pdf, str_repeat(' ', $object['length']), $object['offset'], $object['length']);
        }
        if (!$modifiedObjects) {
            throw new LocalizedException(
                __('Nessun tag firma trovato nel PDF template: impossibile inserire i dati del firmatario.')
            );
        }

        return $this->appendIncrementalUpdate($pdf, $modifiedObjects);
    }

    /**
     * Estrae gli oggetti stream del PDF con contenuto decompresso e la
     * posizione/lunghezza dell'oggetto nel file (per lo sbiancamento in place).
     *
     * Gli oggetti vengono delimitati per confini espliciti (header → endobj),
     * MAI con un'unica regex multi-oggetto: i quantificatori lazy in
     * backtracking possono attraversare i confini e corrompere l'estrazione.
     *
     * @return array<int, array{number: int, content: string, compressed: bool, offset: int, length: int}>
     */
    private function extractStreamObjects(string $pdf): array
    {
        $objects = [];
        if (!preg_match_all('/(\d+)\s+\d+\s+obj\b/', $pdf, $headers, PREG_OFFSET_CAPTURE)) {
            return $objects;
        }

        $headerCount = count($headers[0]);
        for ($i = 0; $i < $headerCount; $i++) {
            $regionStart = $headers[0][$i][1];
            $regionEnd = $i + 1 < $headerCount ? $headers[0][$i + 1][1] : strlen($pdf);
            $region = substr($pdf, $regionStart, $regionEnd - $regionStart);

            // L'oggetto termina a endobj: esclude xref/trailer in coda al file
            $endobjPos = strrpos($region, 'endobj');
            if ($endobjPos === false) {
                continue;
            }
            $region = substr($region, 0, $endobjPos + strlen('endobj'));

            // Inizio dati stream: keyword "stream" subito dopo il dizionario
            if (!preg_match('/>>\s*stream(\r\n|\n)/', $region, $streamMatch, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $dataStart = $streamMatch[0][1] + strlen($streamMatch[0][0]);
            $endstreamPos = strrpos($region, 'endstream');
            if ($endstreamPos === false || $endstreamPos <= $dataStart) {
                continue;
            }
            $dataEnd = $endstreamPos;
            if (substr($region, $dataEnd - 1, 1) === "\n") {
                $dataEnd--;
                if (substr($region, $dataEnd - 1, 1) === "\r") {
                    $dataEnd--;
                }
            }
            $raw = substr($region, $dataStart, $dataEnd - $dataStart);
            $dict = substr($region, 0, $streamMatch[0][1]);

            $compressed = str_contains($dict, '/FlateDecode');
            if ($compressed) {
                // FlateDecode = zlib (RFC 1950); se l'inflate fallisce, o se lo
                // stream decompresso sfora il cap anti-bomba, l'oggetto viene
                // saltato (mai corrotto)
                // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- il warning su stream non-zlib/oltre cap è il fallback previsto
                $content = @gzuncompress($raw, self::MAX_DECOMPRESSED_STREAM);
                if ($content === false) {
                    continue;
                }
            } else {
                $content = $raw;
            }
            $objects[] = [
                'number' => (int)$headers[1][$i][0],
                'content' => $content,
                'compressed' => $compressed,
                'offset' => $regionStart,
                'length' => strlen($region),
            ];
        }

        return $objects;
    }

    private function buildStreamObject(int $number, string $content, bool $compress): string
    {
        $stream = $compress ? gzcompress($content, 9) : $content;
        $dict = $compress
            ? sprintf('<</Filter /FlateDecode /Length %d>>', strlen($stream))
            : sprintf('<</Length %d>>', strlen($stream));

        return sprintf("%d 0 obj\n%s\nstream\n%s\nendstream\nendobj\n", $number, $dict, $stream);
    }

    /**
     * Appende gli oggetti modificati con una nuova sezione xref collegata
     * alla precedente (/Prev), secondo il meccanismo di incremental update.
     * Il formato della nuova sezione (tabella classica o xref stream) segue
     * quello della revisione più recente del PDF sorgente.
     *
     * @param array<int, array{number: int, body: string}> $modifiedObjects
     * @throws LocalizedException
     */
    private function appendIncrementalUpdate(string $pdf, array $modifiedObjects): string
    {
        $prevXref = StartxrefLocator::locate($pdf);
        $info = $this->xrefResolver->resolve($pdf);

        $output = rtrim($pdf, "\n\r") . "\n";
        $offsets = [];
        foreach ($modifiedObjects as $object) {
            $offsets[$object['number']] = strlen($output);
            $output .= $object['body'];
        }

        if ($info->isStreamBased) {
            return $output . $this->buildXrefStreamUpdate($output, $offsets, $info, $prevXref);
        }

        ksort($offsets);
        $xrefOffset = strlen($output);
        $xref = "xref\n";
        foreach ($offsets as $number => $offset) {
            $xref .= sprintf("%d 1\n%010d 00000 n \n", $number, $offset);
        }
        $newTrailer = sprintf(
            "trailer\n<</Size %d /Root %s /Prev %d>>\nstartxref\n%d\n%%%%EOF\n",
            $info->size,
            $info->root,
            $prevXref,
            $xrefOffset
        );

        return $output . $xref . $newTrailer;
    }

    /**
     * Costruisce il nuovo blocco xref stream (senza predictor/compressione,
     * scelta nostra per minimizzare il rischio in scrittura) per un
     * incremental update su un PDF la cui revisione più recente usa già
     * questo formato. L'oggetto xref stream stesso riceve un nuovo numero
     * (info->size, il primo libero) e include anche sé stesso nella tabella.
     *
     * @param array<int, int> $offsets numero oggetto => offset nel file
     */
    private function buildXrefStreamUpdate(string $outputSoFar, array $offsets, XrefInfo $info, int $prevXref): string
    {
        $xrefObjNum = $info->size;
        $xrefOffset = strlen($outputSoFar);
        $offsets[$xrefObjNum] = $xrefOffset;
        ksort($offsets);

        $index = [];
        $rows = '';
        foreach ($offsets as $number => $offset) {
            $index[] = $number;
            $index[] = 1;
            $rows .= chr(1) . $this->encodeUint($offset, 4) . $this->encodeUint(0, 2);
        }

        $dict = sprintf(
            '<</Type /XRef /Size %d /W [1 4 2] /Index [%s] /Root %s /Prev %d /Length %d>>',
            $info->size + 1,
            implode(' ', $index),
            $info->root,
            $prevXref,
            strlen($rows)
        );

        return sprintf(
            "%d 0 obj\n%s\nstream\n%s\nendstream\nendobj\nstartxref\n%d\n%%%%EOF\n",
            $xrefObjNum,
            $dict,
            $rows,
            $xrefOffset
        );
    }

    private function encodeUint(int $value, int $width): string
    {
        $bytes = '';
        for ($i = $width - 1; $i >= 0; $i--) {
            $bytes .= chr(($value >> ($i * 8)) & 0xFF);
        }

        return $bytes;
    }
}
```

- [x] **Step 4: Eseguire i test e verificare che passino**

Run: `vendor/bin/phpunit src/Test/Unit/Model/Pdf/TagReplacerTest.php`
Expected: OK (18 test: i 16 originali, uno rinominato con nuova asserzione, uno nuovo)

- [x] **Step 5: Commit**

```bash
git add src/Model/Pdf/TagReplacer.php src/Test/Unit/Model/Pdf/TagReplacerTest.php
git commit -m "TagReplacer: consuma XrefChainResolver, scrive xref stream se la sorgente lo usa"
```

---

### Task 7: Refactor `TemplateValidator` — dry-run reale + rifiuto object-stream/cifrati

**Files:**
- Modify: `src/Model/Pdf/TemplateValidator.php` (intero file)
- Modify: `src/Test/Unit/Model/Pdf/TemplateValidatorTest.php` (intero file)

**Interfaces:**
- Consumes: `TagReplacer::replaceSignerEmail()` (esistente/Task 6), `XrefChainResolver::resolve()` (Task 5)
- Produces: `TemplateValidator::__construct(TagReplacer $tagReplacer, XrefChainResolver $xrefResolver)` (nuova firma), `TemplateValidator::PREVIEW_SIGNER_EMAIL` (costante pubblica) — usata dal Task 8 (`Controller/Adminhtml/Template/Preview`).

- [x] **Step 1: Scrivere/aggiornare i test**

Sostituire l'intero contenuto di `src/Test/Unit/Model/Pdf/TemplateValidatorTest.php` con:

```php
<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Pdf;

use MageOS\DigitalSignature\Model\Pdf\TagReplacer;
use MageOS\DigitalSignature\Model\Pdf\TemplateValidator;
use MageOS\DigitalSignature\Model\Pdf\Xref\ClassicXrefReader;
use MageOS\DigitalSignature\Model\Pdf\Xref\XrefChainResolver;
use MageOS\DigitalSignature\Model\Pdf\Xref\XrefStreamReader;
use MageOS\DigitalSignature\Test\Unit\Model\Pdf\Xref\XrefStreamFixtureTrait;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class TemplateValidatorTest extends TestCase
{
    use XrefStreamFixtureTrait;

    private TemplateValidator $validator;

    protected function setUp(): void
    {
        $xrefResolver = new XrefChainResolver(new ClassicXrefReader(), new XrefStreamReader());
        $this->validator = new TemplateValidator(new TagReplacer($xrefResolver), $xrefResolver);
    }

    public function testRejectsOversizedFile(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/dimensione massima/');

        $this->validator->validate(str_repeat('a', 10485761));
    }

    public function testRejectsNonPdfContent(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/non è un PDF/');

        $this->validator->validate('<html>non pdf</html>');
    }

    public function testRejectsPdfWithoutAnyXref(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/startxref/');

        $this->validator->validate("%PDF-1.7\ncontenuto {WSIGN#80,20#a@b.it} senza xref");
    }

    public function testRejectsPdfWithoutSignatureTag(): void
    {
        $pdf = "%PDF-1.4\ncontenuto\nxref\n0 1\n0000000000 65535 f \ntrailer\n<</Size 1 /Root 1 0 R>>\nstartxref\n9\n%%EOF\n";

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/Nessun tag firma/');

        $this->validator->validate($pdf);
    }

    public function testAcceptsValidClassicTemplate(): void
    {
        $this->expectNotToPerformAssertions();

        $content = '{WSIGN#80,20#firmatario@example.com}';
        $pdf = "%PDF-1.4\n1 0 obj\n<</Length " . strlen($content) . ">>\nstream\n{$content}\nendstream\nendobj\n";
        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 2\n0000000000 65535 f \n0000000000 00000 n \n";
        $pdf .= "trailer\n<</Size 2 /Root 1 0 R>>\nstartxref\n{$xrefPos}\n%%EOF\n";

        $this->validator->validate($pdf);
    }

    public function testAcceptsValidXrefStreamTemplate(): void
    {
        $this->expectNotToPerformAssertions();

        $header = "%PDF-1.7\n";
        $obj1Offset = strlen($header);
        $content = '{WSIGN#80,20#firmatario@example.com}';
        $obj1 = "1 0 obj\n<</Length " . strlen($content) . ">>\nstream\n{$content}\nendstream\nendobj\n";
        $xrefOffset = strlen($header . $obj1);

        $rows = $this->buildXrefStreamRows([[1, $obj1Offset, 0], [1, $xrefOffset, 0]], 1, 4, 2);
        $xrefObj = "2 0 obj\n<</Type /XRef /Size 3 /Root 1 0 R /W [1 4 2] /Index [1 2]"
            . ' /Length ' . strlen($rows) . ">>\nstream\n{$rows}\nendstream\nendobj\n";

        $pdf = $header . $obj1 . $xrefObj . "startxref\n{$xrefOffset}\n%%EOF\n";

        $this->validator->validate($pdf);
    }

    public function testRejectsPdfWithObjectStreamEntries(): void
    {
        $header = "%PDF-1.7\n";
        $content = '{WSIGN#80,20#firmatario@example.com}';
        $obj1 = "1 0 obj\n<</Length " . strlen($content) . ">>\nstream\n{$content}\nendstream\nendobj\n";
        $xrefOffset = strlen($header . $obj1);

        // riga 1: oggetto 1 dichiarato compresso in un object stream (tipo 2);
        // riga 2: l'oggetto xref stream stesso. Il validator deve rifiutare
        // a prescindere dall'esistenza reale di un container.
        $rows = $this->buildXrefStreamRows([[2, 5, 0], [1, $xrefOffset, 0]], 1, 4, 2);
        $xrefObj = "2 0 obj\n<</Type /XRef /Size 3 /Root 1 0 R /W [1 4 2] /Index [1 2]"
            . ' /Length ' . strlen($rows) . ">>\nstream\n{$rows}\nendstream\nendobj\n";

        $pdf = $header . $obj1 . $xrefObj . "startxref\n{$xrefOffset}\n%%EOF\n";

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/object stream/');

        $this->validator->validate($pdf);
    }

    public function testRejectsEncryptedPdf(): void
    {
        $header = "%PDF-1.7\n";
        $obj1Offset = strlen($header);
        $content = '{WSIGN#80,20#firmatario@example.com}';
        $obj1 = "1 0 obj\n<</Length " . strlen($content) . ">>\nstream\n{$content}\nendstream\nendobj\n";
        $xrefOffset = strlen($header . $obj1);

        $rows = $this->buildXrefStreamRows([[1, $obj1Offset, 0], [1, $xrefOffset, 0]], 1, 4, 2);
        $xrefObj = "2 0 obj\n<</Type /XRef /Size 3 /Root 1 0 R /Encrypt 9 0 R /W [1 4 2] /Index [1 2]"
            . ' /Length ' . strlen($rows) . ">>\nstream\n{$rows}\nendstream\nendobj\n";

        $pdf = $header . $obj1 . $xrefObj . "startxref\n{$xrefOffset}\n%%EOF\n";

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/cifrati/');

        $this->validator->validate($pdf);
    }
}
```

Nota sulle differenze rispetto al file esistente: `testRejectsPdfWithoutClassicXref` è stato rinominato `testRejectsPdfWithoutAnyXref` con fixture e asserzione aggiornate (il vecchio controllo cercava solo il testo `trailer<<`, rifiutato a prescindere dal formato — ora il rifiuto arriva da `StartxrefLocator` quando non c'è proprio uno `startxref`); `testAcceptsValidTemplate` è stato sostituito da `testAcceptsValidClassicTemplate` con una fixture PDF realmente ben formata (xref classica coerente con lo startxref dichiarato, non più solo testo `trailer<<` isolato); `testRejectsPdfWithoutSignatureTag` ha una fixture con xref classica valida (serve perché il resolver ora viene invocato prima del controllo tag). Aggiunti: `testAcceptsValidXrefStreamTemplate`, `testRejectsPdfWithObjectStreamEntries`, `testRejectsEncryptedPdf`.

- [x] **Step 2: Eseguire i test e verificare che falliscano**

Run: `vendor/bin/phpunit src/Test/Unit/Model/Pdf/TemplateValidatorTest.php`
Expected: FAIL (costruttore con firma diversa, metodi/messaggi non ancora aggiornati)

- [x] **Step 3: Aggiornare `TemplateValidator`**

Sostituire l'intero contenuto di `src/Model/Pdf/TemplateValidator.php` con:

```php
<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Pdf;

use MageOS\DigitalSignature\Model\Pdf\Xref\XrefChainResolver;
use Magento\Framework\Exception\LocalizedException;

/**
 * Validazione del PDF template all'upload, PRIMA di renderlo disponibile
 * (decisione di analisi §13-bis): controlli economici su dimensione/magic
 * bytes, rifiuto esplicito di PDF cifrati o con compressione a oggetti
 * (object stream, non supportata in questa fase), infine un vero dry-run
 * della sostituzione tag con un'email segnaposto — esecuzione reale dello
 * stesso path di scrittura usato in produzione, non solo un controllo di
 * presenza: un template accettato non può più fallire per motivi
 * strutturali durante l'elaborazione di un ordine reale.
 */
class TemplateValidator
{
    private const MAX_SIZE_BYTES = 10485760; // allineato al maxFileSize del form

    /**
     * Email segnaposto usata sia dal dry-run di validazione sia
     * dall'anteprima scaricabile (Controller/Adminhtml/Template/Preview):
     * stessa costante, stesso output deterministico.
     */
    public const PREVIEW_SIGNER_EMAIL = 'anteprima.firmatario@esempio-dominio-lungo.test';

    public function __construct(
        private readonly TagReplacer $tagReplacer,
        private readonly XrefChainResolver $xrefResolver
    ) {
    }

    /**
     * @throws LocalizedException con messaggio orientato al merchant
     */
    public function validate(string $pdfContent): void
    {
        if (strlen($pdfContent) > self::MAX_SIZE_BYTES) {
            throw new LocalizedException(__('Il PDF supera la dimensione massima di 10 MB.'));
        }
        if (!str_starts_with($pdfContent, '%PDF')) {
            throw new LocalizedException(__('Il file caricato non è un PDF valido.'));
        }

        $info = $this->xrefResolver->resolve($pdfContent);
        if ($info->isEncrypted) {
            throw new LocalizedException(__(
                'PDF cifrati non sono supportati: rimuovi la protezione/password dal documento e ricarica il file.'
            ));
        }
        if ($info->hasObjectStreams) {
            throw new LocalizedException(__(
                'PDF non supportato: usa compressione a oggetti (object stream), non gestita in questa versione. '
                . 'Disabilita la compressione degli oggetti in fase di esportazione e ricarica il file.'
            ));
        }

        // Dry-run reale: stesso path di scrittura della produzione, email
        // segnaposto, risultato scartato. Solleva LocalizedException propria
        // (PDF non valido / nessun tag) se la sostituzione non è possibile.
        $this->tagReplacer->replaceSignerEmail($pdfContent, self::PREVIEW_SIGNER_EMAIL);
    }
}
```

- [x] **Step 4: Eseguire i test e verificare che passino**

Run: `vendor/bin/phpunit src/Test/Unit/Model/Pdf/TemplateValidatorTest.php`
Expected: OK (8 test)

- [x] **Step 5: Eseguire l'intera suite per verificare l'assenza di regressioni**

Run: `vendor/bin/phpunit`
Expected: OK, tutti i test verdi (nessun failure/warning: `failOnWarning`/`failOnRisky` sono attivi in `phpunit.xml.dist`)

- [x] **Step 6: Commit**

```bash
git add src/Model/Pdf/TemplateValidator.php src/Test/Unit/Model/Pdf/TemplateValidatorTest.php
git commit -m "TemplateValidator: dry-run reale + rifiuto PDF cifrati/object-stream"
```

---

### Task 8: Controller `Adminhtml/Template/Preview` + pulsante anteprima

**Files:**
- Create: `src/Controller/Adminhtml/Template/Preview.php`
- Create: `src/Block/Adminhtml/Template/Edit/PreviewButton.php`
- Modify: `src/view/adminhtml/ui_component/digitalsignature_template_form.xml`

**Interfaces:**
- Consumes: `TemplateValidator::PREVIEW_SIGNER_EMAIL` (Task 7), `TagReplacer::replaceSignerEmail()` (Task 6), `TemplateRepository::getPdfPathForStore(int $templateId, int $storeId): ?array` (esistente, restituisce `[?, string $path]` come già usato in `Model/Service/DocumentProcessor.php:72`)

Questo task non introduce logica nuova da testare in isolamento (è wiring di controller/UI, coerente con i pattern già presenti in `Controller/Adminhtml/Document/Download.php` e `Controller/Adminhtml/Template/Upload.php`); la verifica avviene tramite lettura/lint, non PHPUnit (i controller admin non sono coperti dalla suite standalone del modulo, per gli stessi motivi già validi per `Document/Download.php`).

- [x] **Step 1: Creare il controller `Preview`**

```php
<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Controller\Adminhtml\Template;

use MageOS\DigitalSignature\Model\Pdf\TagReplacer;
use MageOS\DigitalSignature\Model\Pdf\TemplateValidator;
use MageOS\DigitalSignature\Model\ResourceModel\Template as TemplateResource;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Filesystem;
use Psr\Log\LoggerInterface;

/**
 * Rigenera on-demand (nessuna persistenza) il PDF del template con il tag
 * firma sostituito da un'email segnaposto fissa, per dare al merchant una
 * conferma visiva che la sostituzione avvenga nella posizione corretta.
 * Stesso path di scrittura usato in produzione e nel dry-run di validazione
 * dell'upload (TemplateValidator::PREVIEW_SIGNER_EMAIL).
 */
class Preview extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_DigitalSignature::template';

    public function __construct(
        Action\Context $context,
        private readonly TemplateResource $templateResource,
        private readonly TagReplacer $tagReplacer,
        private readonly Filesystem $filesystem,
        private readonly RawFactory $rawFactory,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $templateId = (int)$this->getRequest()->getParam('template_id');
        $storeId = (int)$this->getRequest()->getParam('store', 0);

        try {
            $fileRow = $this->templateResource->getPdfPathForStore($templateId, $storeId);
            if ($fileRow === null) {
                throw new \RuntimeException((string)__('Il template non ha un file PDF caricato per questa vista.'));
            }
            [, $templatePdfPath] = $fileRow;

            $mediaDir = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
            if (!$mediaDir->isExist($templatePdfPath)) {
                throw new \RuntimeException((string)__('File PDF del template non trovato.'));
            }

            $preview = $this->tagReplacer->replaceSignerEmail(
                $mediaDir->readFile($templatePdfPath),
                TemplateValidator::PREVIEW_SIGNER_EMAIL
            );

            /** @var Raw $result */
            $result = $this->rawFactory->create();
            $result->setHeader('Content-Type', 'application/pdf', true);
            $result->setHeader('X-Content-Type-Options', 'nosniff', true);
            $result->setHeader(
                'Content-Disposition',
                sprintf('attachment; filename="anteprima-template-%d.pdf"', $templateId),
                true
            );
            $result->setHeader('Content-Length', (string)strlen($preview), true);
            $result->setContents($preview);

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('DigitalSignature: anteprima template fallita: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(
                __('Impossibile generare l\'anteprima: %1', $e->getMessage())
            );
            /** @var Redirect $redirect */
            $redirect = $this->resultRedirectFactory->create();

            return $redirect->setPath('*/*/edit', ['template_id' => $templateId, 'store' => $storeId]);
        }
    }
}
```

- [x] **Step 2: Creare il pulsante `PreviewButton`**

```php
<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Block\Adminhtml\Template\Edit;

use Magento\Backend\Block\Widget\Context;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class PreviewButton implements ButtonProviderInterface
{
    public function __construct(protected readonly Context $context)
    {
    }

    public function getButtonData(): array
    {
        $templateId = (int)$this->context->getRequest()->getParam('template_id');
        if (!$templateId) {
            return [];
        }
        $storeId = (int)$this->context->getRequest()->getParam('store', 0);
        $previewUrl = $this->context->getUrlBuilder()->getUrl(
            '*/*/preview',
            ['template_id' => $templateId, 'store' => $storeId]
        );

        return [
            'label' => __('Scarica anteprima elaborata'),
            'class' => 'action-secondary',
            'on_click' => sprintf("location.href = '%s';", $previewUrl),
            'sort_order' => 15,
        ];
    }
}
```

- [x] **Step 3: Registrare il pulsante nel form**

In `src/view/adminhtml/ui_component/digitalsignature_template_form.xml`, individuare il blocco `<button name="delete" .../>` (riga 14) e aggiungere subito dopo:

```xml
            <button name="preview" class="MageOS\DigitalSignature\Block\Adminhtml\Template\Edit\PreviewButton"/>
```

- [x] **Step 4: Aggiungere la voce di routing ACL (nessuna modifica necessaria)**

`Preview::ADMIN_RESOURCE = 'MageOS_DigitalSignature::template'` riusa la risorsa ACL già dichiarata in `etc/acl.xml`; nessuna modifica a quel file.

- [x] **Step 5: Verifica statica (lint PHP + XML)**

Run: `php -l src/Controller/Adminhtml/Template/Preview.php && php -l src/Block/Adminhtml/Template/Edit/PreviewButton.php`
Expected: `No syntax errors detected` per entrambi i file

Run: `php -r "var_dump(simplexml_load_file('src/view/adminhtml/ui_component/digitalsignature_template_form.xml') !== false);"`
Expected: `bool(true)`

- [x] **Step 6: Commit**

```bash
git add src/Controller/Adminhtml/Template/Preview.php src/Block/Adminhtml/Template/Edit/PreviewButton.php src/view/adminhtml/ui_component/digitalsignature_template_form.xml
git commit -m "Aggiunge anteprima on-demand del template elaborato (controller + pulsante admin)"
```

---

### Task 9: Traduzioni (i18n) delle nuove stringhe utente-visibili

**Files:**
- Modify: `src/i18n/en_US.csv`
- Modify: `src/i18n/de_DE.csv`
- Modify: `src/i18n/es_ES.csv`
- Modify: `src/i18n/fr_FR.csv`
- Modify: `src/i18n/nl_NL.csv`
- Modify: `src/i18n/pt_BR.csv`
- Modify: `src/i18n/zh_Hans_CN.csv`

Il codice sorgente usa l'italiano come msgid (`it_IT.csv` non necessita voci per queste stringhe, come già per tutte le altre nel modulo). La stringa `"PDF non supportato: trailer privo di Size/Root."` è riusata verbatim da `ClassicXrefReader` (Task 3) ed è **già tradotta** in tutte le lingue: nessuna riga aggiuntiva necessaria per quella.

- [x] **Step 1: Aggiungere le righe seguenti in coda a ciascun file CSV** (una riga per stringa, formato `"msgid italiano","msgstr tradotto"`)

**`en_US.csv`:**
```csv
"PDF non supportato: sezione cross-reference classica non trovata alla posizione attesa.","Unsupported PDF: classic cross-reference section not found at the expected position."
"PDF non supportato: trailer non trovato dopo la tabella cross-reference.","Unsupported PDF: trailer not found after the cross-reference table."
"PDF non supportato: oggetto cross-reference stream non riconosciuto.","Unsupported PDF: cross-reference stream object not recognized."
"PDF non supportato: stream cross-reference non riconosciuto.","Unsupported PDF: cross-reference stream not recognized."
"PDF non supportato: stream cross-reference incompleto.","Unsupported PDF: incomplete cross-reference stream."
"PDF non supportato: atteso un oggetto /Type /XRef.","Unsupported PDF: expected an object of /Type /XRef."
"PDF non supportato: dizionario cross-reference stream incompleto.","Unsupported PDF: incomplete cross-reference stream dictionary."
"PDF non supportato: stream cross-reference non decomprimibile.","Unsupported PDF: cross-reference stream could not be decompressed."
"PDF non supportato: predictor cross-reference stream non gestito (valore %1).","Unsupported PDF: cross-reference stream predictor not handled (value %1)."
"PDF non supportato: parametri di predizione cross-reference stream non gestiti.","Unsupported PDF: cross-reference stream prediction parameters not handled."
"PDF non supportato: larghezze /W cross-reference stream non valide.","Unsupported PDF: invalid cross-reference stream /W widths."
"PDF non supportato: stream cross-reference troppo corto per le voci dichiarate.","Unsupported PDF: cross-reference stream too short for the declared entries."
"PDF non supportato: stream cross-reference con predizione PNG malformato.","Unsupported PDF: cross-reference stream with malformed PNG prediction."
"PDF non supportato: filtro PNG riga cross-reference stream non riconosciuto (%1).","Unsupported PDF: unrecognized PNG row filter in cross-reference stream (%1)."
"PDF non supportato: catena cross-reference con troppe revisioni collegate (max %1).","Unsupported PDF: cross-reference chain has too many linked revisions (max %1)."
"PDF cifrati non sono supportati: rimuovi la protezione/password dal documento e ricarica il file.","Encrypted PDFs are not supported: remove the protection/password from the document and re-upload the file."
"PDF non supportato: usa compressione a oggetti (object stream), non gestita in questa versione. Disabilita la compressione degli oggetti in fase di esportazione e ricarica il file.","Unsupported PDF: it uses object compression (object streams), not handled in this version. Disable object compression when exporting and re-upload the file."
"Il template non ha un file PDF caricato per questa vista.","The template has no PDF file uploaded for this store view."
"File PDF del template non trovato.","Template PDF file not found."
"Impossibile generare l'anteprima: %1","Unable to generate the preview: %1"
"Scarica anteprima elaborata","Download processed preview"
```

**`de_DE.csv`:**
```csv
"PDF non supportato: sezione cross-reference classica non trovata alla posizione attesa.","Nicht unterstütztes PDF: klassischer Cross-Reference-Abschnitt an der erwarteten Position nicht gefunden."
"PDF non supportato: trailer non trovato dopo la tabella cross-reference.","Nicht unterstütztes PDF: Trailer nach der Cross-Reference-Tabelle nicht gefunden."
"PDF non supportato: oggetto cross-reference stream non riconosciuto.","Nicht unterstütztes PDF: Cross-Reference-Stream-Objekt nicht erkannt."
"PDF non supportato: stream cross-reference non riconosciuto.","Nicht unterstütztes PDF: Cross-Reference-Stream nicht erkannt."
"PDF non supportato: stream cross-reference incompleto.","Nicht unterstütztes PDF: unvollständiger Cross-Reference-Stream."
"PDF non supportato: atteso un oggetto /Type /XRef.","Nicht unterstütztes PDF: ein Objekt vom /Type /XRef wurde erwartet."
"PDF non supportato: dizionario cross-reference stream incompleto.","Nicht unterstütztes PDF: unvollständiges Cross-Reference-Stream-Dictionary."
"PDF non supportato: stream cross-reference non decomprimibile.","Nicht unterstütztes PDF: Cross-Reference-Stream konnte nicht dekomprimiert werden."
"PDF non supportato: predictor cross-reference stream non gestito (valore %1).","Nicht unterstütztes PDF: Predictor des Cross-Reference-Streams nicht unterstützt (Wert %1)."
"PDF non supportato: parametri di predizione cross-reference stream non gestiti.","Nicht unterstütztes PDF: Vorhersageparameter des Cross-Reference-Streams nicht unterstützt."
"PDF non supportato: larghezze /W cross-reference stream non valide.","Nicht unterstütztes PDF: ungültige /W-Breiten des Cross-Reference-Streams."
"PDF non supportato: stream cross-reference troppo corto per le voci dichiarate.","Nicht unterstütztes PDF: Cross-Reference-Stream zu kurz für die deklarierten Einträge."
"PDF non supportato: stream cross-reference con predizione PNG malformato.","Nicht unterstütztes PDF: Cross-Reference-Stream mit fehlerhafter PNG-Vorhersage."
"PDF non supportato: filtro PNG riga cross-reference stream non riconosciuto (%1).","Nicht unterstütztes PDF: nicht erkannter PNG-Zeilenfilter im Cross-Reference-Stream (%1)."
"PDF non supportato: catena cross-reference con troppe revisioni collegate (max %1).","Nicht unterstütztes PDF: Cross-Reference-Kette hat zu viele verknüpfte Revisionen (max. %1)."
"PDF cifrati non sono supportati: rimuovi la protezione/password dal documento e ricarica il file.","Verschlüsselte PDFs werden nicht unterstützt: Entfernen Sie den Schutz/das Passwort aus dem Dokument und laden Sie die Datei erneut hoch."
"PDF non supportato: usa compressione a oggetti (object stream), non gestita in questa versione. Disabilita la compressione degli oggetti in fase di esportazione e ricarica il file.","Nicht unterstütztes PDF: Es verwendet Objektkomprimierung (Object Streams), die in dieser Version nicht unterstützt wird. Deaktivieren Sie die Objektkomprimierung beim Export und laden Sie die Datei erneut hoch."
"Il template non ha un file PDF caricato per questa vista.","Die Vorlage hat für diese Storeansicht keine hochgeladene PDF-Datei."
"File PDF del template non trovato.","PDF-Datei der Vorlage nicht gefunden."
"Impossibile generare l'anteprima: %1","Vorschau konnte nicht erstellt werden: %1"
"Scarica anteprima elaborata","Verarbeitete Vorschau herunterladen"
```

**`es_ES.csv`:**
```csv
"PDF non supportato: sezione cross-reference classica non trovata alla posizione attesa.","PDF no compatible: no se encontró la sección de referencia cruzada clásica en la posición esperada."
"PDF non supportato: trailer non trovato dopo la tabella cross-reference.","PDF no compatible: no se encontró el trailer después de la tabla de referencia cruzada."
"PDF non supportato: oggetto cross-reference stream non riconosciuto.","PDF no compatible: objeto de flujo de referencia cruzada no reconocido."
"PDF non supportato: stream cross-reference non riconosciuto.","PDF no compatible: flujo de referencia cruzada no reconocido."
"PDF non supportato: stream cross-reference incompleto.","PDF no compatible: flujo de referencia cruzada incompleto."
"PDF non supportato: atteso un oggetto /Type /XRef.","PDF no compatible: se esperaba un objeto de /Type /XRef."
"PDF non supportato: dizionario cross-reference stream incompleto.","PDF no compatible: diccionario del flujo de referencia cruzada incompleto."
"PDF non supportato: stream cross-reference non decomprimibile.","PDF no compatible: no se pudo descomprimir el flujo de referencia cruzada."
"PDF non supportato: predictor cross-reference stream non gestito (valore %1).","PDF no compatible: predictor del flujo de referencia cruzada no compatible (valor %1)."
"PDF non supportato: parametri di predizione cross-reference stream non gestiti.","PDF no compatible: parámetros de predicción del flujo de referencia cruzada no compatibles."
"PDF non supportato: larghezze /W cross-reference stream non valide.","PDF no compatible: anchos /W del flujo de referencia cruzada no válidos."
"PDF non supportato: stream cross-reference troppo corto per le voci dichiarate.","PDF no compatible: flujo de referencia cruzada demasiado corto para las entradas declaradas."
"PDF non supportato: stream cross-reference con predizione PNG malformato.","PDF no compatible: flujo de referencia cruzada con predicción PNG malformada."
"PDF non supportato: filtro PNG riga cross-reference stream non riconosciuto (%1).","PDF no compatible: filtro PNG de fila no reconocido en el flujo de referencia cruzada (%1)."
"PDF non supportato: catena cross-reference con troppe revisioni collegate (max %1).","PDF no compatible: la cadena de referencia cruzada tiene demasiadas revisiones enlazadas (máx. %1)."
"PDF cifrati non sono supportati: rimuovi la protezione/password dal documento e ricarica il file.","Los PDF cifrados no son compatibles: elimina la protección/contraseña del documento y vuelve a cargar el archivo."
"PDF non supportato: usa compressione a oggetti (object stream), non gestita in questa versione. Disabilita la compressione degli oggetti in fase di esportazione e ricarica il file.","PDF no compatible: utiliza compresión de objetos (object streams), no compatible en esta versión. Desactiva la compresión de objetos al exportar y vuelve a cargar el archivo."
"Il template non ha un file PDF caricato per questa vista.","La plantilla no tiene un archivo PDF cargado para esta vista de tienda."
"File PDF del template non trovato.","No se encontró el archivo PDF de la plantilla."
"Impossibile generare l'anteprima: %1","No se pudo generar la vista previa: %1"
"Scarica anteprima elaborata","Descargar vista previa procesada"
```

**`fr_FR.csv`:**
```csv
"PDF non supportato: sezione cross-reference classica non trovata alla posizione attesa.","PDF non pris en charge : section de référence croisée classique introuvable à la position attendue."
"PDF non supportato: trailer non trovato dopo la tabella cross-reference.","PDF non pris en charge : trailer introuvable après la table de référence croisée."
"PDF non supportato: oggetto cross-reference stream non riconosciuto.","PDF non pris en charge : objet de flux de référence croisée non reconnu."
"PDF non supportato: stream cross-reference non riconosciuto.","PDF non pris en charge : flux de référence croisée non reconnu."
"PDF non supportato: stream cross-reference incompleto.","PDF non pris en charge : flux de référence croisée incomplet."
"PDF non supportato: atteso un oggetto /Type /XRef.","PDF non pris en charge : un objet de /Type /XRef était attendu."
"PDF non supportato: dizionario cross-reference stream incompleto.","PDF non pris en charge : dictionnaire du flux de référence croisée incomplet."
"PDF non supportato: stream cross-reference non decomprimibile.","PDF non pris en charge : impossible de décompresser le flux de référence croisée."
"PDF non supportato: predictor cross-reference stream non gestito (valore %1).","PDF non pris en charge : predictor du flux de référence croisée non pris en charge (valeur %1)."
"PDF non supportato: parametri di predizione cross-reference stream non gestiti.","PDF non pris en charge : paramètres de prédiction du flux de référence croisée non pris en charge."
"PDF non supportato: larghezze /W cross-reference stream non valide.","PDF non pris en charge : largeurs /W du flux de référence croisée non valides."
"PDF non supportato: stream cross-reference troppo corto per le voci dichiarate.","PDF non pris en charge : flux de référence croisée trop court pour les entrées déclarées."
"PDF non supportato: stream cross-reference con predizione PNG malformato.","PDF non pris en charge : flux de référence croisée avec prédiction PNG malformée."
"PDF non supportato: filtro PNG riga cross-reference stream non riconosciuto (%1).","PDF non pris en charge : filtre PNG de ligne non reconnu dans le flux de référence croisée (%1)."
"PDF non supportato: catena cross-reference con troppe revisioni collegate (max %1).","PDF non pris en charge : la chaîne de référence croisée comporte trop de révisions liées (max %1)."
"PDF cifrati non sono supportati: rimuovi la protezione/password dal documento e ricarica il file.","Les PDF chiffrés ne sont pas pris en charge : retirez la protection/le mot de passe du document et rechargez le fichier."
"PDF non supportato: usa compressione a oggetti (object stream), non gestita in questa versione. Disabilita la compressione degli oggetti in fase di esportazione e ricarica il file.","PDF non pris en charge : il utilise la compression d'objets (object streams), non gérée dans cette version. Désactivez la compression des objets à l'export et rechargez le fichier."
"Il template non ha un file PDF caricato per questa vista.","Le modèle n'a pas de fichier PDF téléchargé pour cette vue de magasin."
"File PDF del template non trovato.","Fichier PDF du modèle introuvable."
"Impossibile generare l'anteprima: %1","Impossible de générer l'aperçu : %1"
"Scarica anteprima elaborata","Télécharger l'aperçu traité"
```

**`nl_NL.csv`:**
```csv
"PDF non supportato: sezione cross-reference classica non trovata alla posizione attesa.","Niet-ondersteunde PDF: klassieke cross-reference-sectie niet gevonden op de verwachte positie."
"PDF non supportato: trailer non trovato dopo la tabella cross-reference.","Niet-ondersteunde PDF: trailer niet gevonden na de cross-reference-tabel."
"PDF non supportato: oggetto cross-reference stream non riconosciuto.","Niet-ondersteunde PDF: cross-reference-streamobject niet herkend."
"PDF non supportato: stream cross-reference non riconosciuto.","Niet-ondersteunde PDF: cross-reference-stream niet herkend."
"PDF non supportato: stream cross-reference incompleto.","Niet-ondersteunde PDF: onvolledige cross-reference-stream."
"PDF non supportato: atteso un oggetto /Type /XRef.","Niet-ondersteunde PDF: een object van /Type /XRef werd verwacht."
"PDF non supportato: dizionario cross-reference stream incompleto.","Niet-ondersteunde PDF: onvolledig dictionary van de cross-reference-stream."
"PDF non supportato: stream cross-reference non decomprimibile.","Niet-ondersteunde PDF: cross-reference-stream kon niet worden gedecomprimeerd."
"PDF non supportato: predictor cross-reference stream non gestito (valore %1).","Niet-ondersteunde PDF: predictor van de cross-reference-stream niet ondersteund (waarde %1)."
"PDF non supportato: parametri di predizione cross-reference stream non gestiti.","Niet-ondersteunde PDF: predictieparameters van de cross-reference-stream niet ondersteund."
"PDF non supportato: larghezze /W cross-reference stream non valide.","Niet-ondersteunde PDF: ongeldige /W-breedtes van de cross-reference-stream."
"PDF non supportato: stream cross-reference troppo corto per le voci dichiarate.","Niet-ondersteunde PDF: cross-reference-stream te kort voor de opgegeven items."
"PDF non supportato: stream cross-reference con predizione PNG malformato.","Niet-ondersteunde PDF: cross-reference-stream met misvormde PNG-predictie."
"PDF non supportato: filtro PNG riga cross-reference stream non riconosciuto (%1).","Niet-ondersteunde PDF: niet-herkend PNG-rijfilter in de cross-reference-stream (%1)."
"PDF non supportato: catena cross-reference con troppe revisioni collegate (max %1).","Niet-ondersteunde PDF: de cross-reference-keten heeft te veel gekoppelde revisies (max %1)."
"PDF cifrati non sono supportati: rimuovi la protezione/password dal documento e ricarica il file.","Versleutelde PDF's worden niet ondersteund: verwijder de beveiliging/het wachtwoord uit het document en upload het bestand opnieuw."
"PDF non supportato: usa compressione a oggetti (object stream), non gestita in questa versione. Disabilita la compressione degli oggetti in fase di esportazione e ricarica il file.","Niet-ondersteunde PDF: gebruikt objectcompressie (object streams), niet ondersteund in deze versie. Schakel objectcompressie uit bij het exporteren en upload het bestand opnieuw."
"Il template non ha un file PDF caricato per questa vista.","De template heeft geen geüpload PDF-bestand voor deze winkelweergave."
"File PDF del template non trovato.","PDF-bestand van de template niet gevonden."
"Impossibile generare l'anteprima: %1","Kan de voorbeeldweergave niet genereren: %1"
"Scarica anteprima elaborata","Verwerkte voorbeeldweergave downloaden"
```

**`pt_BR.csv`:**
```csv
"PDF non supportato: sezione cross-reference classica non trovata alla posizione attesa.","PDF não suportado: seção de referência cruzada clássica não encontrada na posição esperada."
"PDF non supportato: trailer non trovato dopo la tabella cross-reference.","PDF não suportado: trailer não encontrado após a tabela de referência cruzada."
"PDF non supportato: oggetto cross-reference stream non riconosciuto.","PDF não suportado: objeto de fluxo de referência cruzada não reconhecido."
"PDF non supportato: stream cross-reference non riconosciuto.","PDF não suportado: fluxo de referência cruzada não reconhecido."
"PDF non supportato: stream cross-reference incompleto.","PDF não suportado: fluxo de referência cruzada incompleto."
"PDF non supportato: atteso un oggetto /Type /XRef.","PDF não suportado: era esperado um objeto de /Type /XRef."
"PDF non supportato: dizionario cross-reference stream incompleto.","PDF não suportado: dicionário do fluxo de referência cruzada incompleto."
"PDF non supportato: stream cross-reference non decomprimibile.","PDF não suportado: não foi possível descompactar o fluxo de referência cruzada."
"PDF non supportato: predictor cross-reference stream non gestito (valore %1).","PDF não suportado: predictor do fluxo de referência cruzada não suportado (valor %1)."
"PDF non supportato: parametri di predizione cross-reference stream non gestiti.","PDF não suportado: parâmetros de predição do fluxo de referência cruzada não suportados."
"PDF non supportato: larghezze /W cross-reference stream non valide.","PDF não suportado: larguras /W do fluxo de referência cruzada inválidas."
"PDF non supportato: stream cross-reference troppo corto per le voci dichiarate.","PDF não suportado: fluxo de referência cruzada muito curto para as entradas declaradas."
"PDF non supportato: stream cross-reference con predizione PNG malformato.","PDF não suportado: fluxo de referência cruzada com predição PNG malformada."
"PDF non supportato: filtro PNG riga cross-reference stream non riconosciuto (%1).","PDF não suportado: filtro PNG de linha não reconhecido no fluxo de referência cruzada (%1)."
"PDF non supportato: catena cross-reference con troppe revisioni collegate (max %1).","PDF não suportado: a cadeia de referência cruzada tem revisões vinculadas em excesso (máx. %1)."
"PDF cifrati non sono supportati: rimuovi la protezione/password dal documento e ricarica il file.","PDFs criptografados não são suportados: remova a proteção/senha do documento e envie o arquivo novamente."
"PDF non supportato: usa compressione a oggetti (object stream), non gestita in questa versione. Disabilita la compressione degli oggetti in fase di esportazione e ricarica il file.","PDF não suportado: usa compressão de objetos (object streams), não suportada nesta versão. Desative a compressão de objetos ao exportar e envie o arquivo novamente."
"Il template non ha un file PDF caricato per questa vista.","O template não possui um arquivo PDF enviado para esta visão de loja."
"File PDF del template non trovato.","Arquivo PDF do template não encontrado."
"Impossibile generare l'anteprima: %1","Não foi possível gerar a pré-visualização: %1"
"Scarica anteprima elaborata","Baixar pré-visualização processada"
```

**`zh_Hans_CN.csv`:**
```csv
"PDF non supportato: sezione cross-reference classica non trovata alla posizione attesa.","不支持的 PDF：在预期位置未找到经典交叉引用表。"
"PDF non supportato: trailer non trovato dopo la tabella cross-reference.","不支持的 PDF：在交叉引用表之后未找到 trailer。"
"PDF non supportato: oggetto cross-reference stream non riconosciuto.","不支持的 PDF：无法识别交叉引用流对象。"
"PDF non supportato: stream cross-reference non riconosciuto.","不支持的 PDF：无法识别交叉引用流。"
"PDF non supportato: stream cross-reference incompleto.","不支持的 PDF：交叉引用流不完整。"
"PDF non supportato: atteso un oggetto /Type /XRef.","不支持的 PDF：应为 /Type /XRef 对象。"
"PDF non supportato: dizionario cross-reference stream incompleto.","不支持的 PDF：交叉引用流字典不完整。"
"PDF non supportato: stream cross-reference non decomprimibile.","不支持的 PDF：无法解压交叉引用流。"
"PDF non supportato: predictor cross-reference stream non gestito (valore %1).","不支持的 PDF：不支持的交叉引用流预测器（值 %1）。"
"PDF non supportato: parametri di predizione cross-reference stream non gestiti.","不支持的 PDF：不支持的交叉引用流预测参数。"
"PDF non supportato: larghezze /W cross-reference stream non valide.","不支持的 PDF：交叉引用流 /W 宽度无效。"
"PDF non supportato: stream cross-reference troppo corto per le voci dichiarate.","不支持的 PDF：交叉引用流对于声明的条目而言过短。"
"PDF non supportato: stream cross-reference con predizione PNG malformato.","不支持的 PDF：交叉引用流的 PNG 预测格式错误。"
"PDF non supportato: filtro PNG riga cross-reference stream non riconosciuto (%1).","不支持的 PDF：交叉引用流中无法识别的 PNG 行过滤器（%1）。"
"PDF non supportato: catena cross-reference con troppe revisioni collegate (max %1).","不支持的 PDF：交叉引用链接的修订版本过多（最多 %1 个）。"
"PDF cifrati non sono supportati: rimuovi la protezione/password dal documento e ricarica il file.","不支持加密的 PDF：请移除文档的保护/密码后重新上传文件。"
"PDF non supportato: usa compressione a oggetti (object stream), non gestita in questa versione. Disabilita la compressione degli oggetti in fase di esportazione e ricarica il file.","不支持的 PDF：使用了对象压缩（object stream），此版本尚不支持。请在导出时禁用对象压缩后重新上传文件。"
"Il template non ha un file PDF caricato per questa vista.","该模板在此商店视图下未上传 PDF 文件。"
"File PDF del template non trovato.","未找到模板的 PDF 文件。"
"Impossibile generare l'anteprima: %1","无法生成预览：%1"
"Scarica anteprima elaborata","下载已处理的预览"
```

- [x] **Step 2: Verifica formale dei CSV**

Run: `for f in src/i18n/*.csv; do php -r "\$h=fopen('$f','r'); while(\$row=fgetcsv(\$h)){ if(count(\$row)!==2){ echo \"$f: riga malformata\n\"; } } fclose(\$h);"; done`
Expected: nessun output (tutte le righe hanno esattamente 2 colonne)

- [x] **Step 3: Commit**

```bash
git add src/i18n/en_US.csv src/i18n/de_DE.csv src/i18n/es_ES.csv src/i18n/fr_FR.csv src/i18n/nl_NL.csv src/i18n/pt_BR.csv src/i18n/zh_Hans_CN.csv
git commit -m "i18n: traduce i nuovi messaggi per supporto xref stream e anteprima template"
```

---

### Task 10: Verifica finale della suite completa

**Files:** nessuno (solo verifica)

- [x] **Step 1: Eseguire l'intera suite PHPUnit**

Run: `vendor/bin/phpunit`
Expected: OK, tutti i test verdi (nessun failure/error/warning)

- [x] **Step 2: Lint PHP su tutti i file nuovi/modificati**

Run:
```bash
for f in src/Model/Pdf/Xref/*.php src/Model/Pdf/TagReplacer.php src/Model/Pdf/TemplateValidator.php src/Controller/Adminhtml/Template/Preview.php src/Block/Adminhtml/Template/Edit/PreviewButton.php src/Test/Unit/Model/Pdf/Xref/*.php src/Test/Unit/Model/Pdf/TagReplacerTest.php src/Test/Unit/Model/Pdf/TemplateValidatorTest.php; do php -l "$f"; done
```
Expected: `No syntax errors detected` per ogni file

- [x] **Step 3: Se il repository ha phpcs configurato (Magento2 coding standard, come da CI esistente), eseguirlo sui file nuovi/modificati**

Run: `vendor/bin/phpcs --standard=Magento2 src/Model/Pdf/Xref/ src/Model/Pdf/TagReplacer.php src/Model/Pdf/TemplateValidator.php src/Controller/Adminhtml/Template/Preview.php src/Block/Adminhtml/Template/Edit/PreviewButton.php` (se `phpcs`/`Magento2` standard non sono installati in questo ambiente di sviluppo standalone, saltare questo step: la CI del repository lo esegue già su push)
Expected: nessuna violazione, oppure step saltato con nota se lo standard non è disponibile localmente

- [x] **Step 4: Nessun commit in questo task** (solo verifica; se il lint/phpcs segnala problemi, tornare al task pertinente, correggere e ri-committare lì)

---

## Riepilogo file toccati

| File | Stato |
|---|---|
| `src/Model/Pdf/Xref/DictFields.php` | nuovo |
| `src/Model/Pdf/Xref/StartxrefLocator.php` | nuovo |
| `src/Model/Pdf/Xref/XrefLink.php` | nuovo |
| `src/Model/Pdf/Xref/ClassicXrefReader.php` | nuovo |
| `src/Model/Pdf/Xref/XrefStreamReader.php` | nuovo |
| `src/Model/Pdf/Xref/XrefInfo.php` | nuovo |
| `src/Model/Pdf/Xref/XrefChainResolver.php` | nuovo |
| `src/Model/Pdf/TagReplacer.php` | modificato |
| `src/Model/Pdf/TemplateValidator.php` | modificato |
| `src/Controller/Adminhtml/Template/Preview.php` | nuovo |
| `src/Block/Adminhtml/Template/Edit/PreviewButton.php` | nuovo |
| `src/view/adminhtml/ui_component/digitalsignature_template_form.xml` | modificato |
| `src/i18n/{en_US,de_DE,es_ES,fr_FR,nl_NL,pt_BR,zh_Hans_CN}.csv` | modificati |
| `src/Test/Unit/Model/Pdf/Xref/*Test.php` + `XrefStreamFixtureTrait.php` | nuovi |
| `src/Test/Unit/Model/Pdf/TagReplacerTest.php` | modificato |
| `src/Test/Unit/Model/Pdf/TemplateValidatorTest.php` | modificato |

## Fuori scope (non in questo piano)

- Supporto object stream (compressione multi-oggetto) — intervento futuro separato, come da spec.
- Supporto PDF cifrati.
- Fixture PDF **reale** generata da un producer esterno (LibreOffice/Ghostscript) per test di integrazione aggiuntivi — i test di questo piano usano fixture costruite a mano (byte-level), sufficienti a validare la logica; una fixture reale resta un miglioramento facoltativo futuro.
