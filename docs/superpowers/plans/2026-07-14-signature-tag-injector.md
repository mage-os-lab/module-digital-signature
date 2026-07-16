# Inserimento tag firma in coordinate precise (backend) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the backend capability to insert a `{WSIGN#W,H#email}` signature tag into an arbitrary point of a PDF page, given the page number and precise coordinates — a new write capability (today the module only *replaces* an existing tag, never *creates* one).

**Architecture:** Extend the existing xref readers (`ClassicXrefReader`, `XrefStreamReader`) to expose a per-object offset table, add a `PageTreeResolver` that walks `Root → /Pages → /Kids[N] → Page` using that table, extract the incremental-update writing logic already in `TagReplacer` into a shared `IncrementalUpdateWriter`, and build `SignatureTagInjector` on top of both.

**Tech Stack:** PHP 8.1+, pure PHP PDF manipulation (no Composer/system PDF libraries), PHPUnit standalone (`tests/bootstrap.php`, no real Magento install required).

## Global Constraints

- Zero external dependencies (no Composer PDF libraries, no system binaries) — same constraint as the rest of the module's PDF handling.
- Inserted tag text must be genuine, visible PDF text (`Tj`/`TJ` with a real font), never an invisible trick — matches how a hand-typed tag behaves today.
- If no font resource exists on the target page, reference a standard PDF font (Helvetica) — no font embedding.
- Reject explicitly (never silently mishandle): nested `/Pages` trees, pages with `/Rotate != 0`, `/Resources` as an indirect reference (or absent).
- No changes to `TemplateValidator`'s public contract.
- All new pure-logic components get PHPUnit unit tests in `src/Test/Unit`, standalone (no real Magento install, matching `tests/bootstrap.php`). No test coverage required for Magento controllers/blocks (none are touched by this plan — wiring into `Upload.php` is out of scope, deferred to the frontend plan).
- Every existing test in `src/Test/Unit` must keep passing unmodified in assertions (only constructor wiring in two test `setUp()` methods changes, per Task 6).

---

### Task 1: `DictFields` — balanced dict extraction, name and ref-array helpers

**Files:**
- Modify: `src/Model/Pdf/Xref/DictFields.php`
- Test: `src/Test/Unit/Model/Pdf/Xref/DictFieldsTest.php`

**Interfaces:**
- Produces: `DictFields::extractName(string $dict, string $key): ?string`, `DictFields::extractRefArray(string $dict, string $key): ?array` (returns `string[]` of `"N G R"` tokens or `null`), `DictFields::extractBalancedDict(string $text, int $contentStart): array` (returns `[string $content, int $closeStart]`, throws `LocalizedException` if unterminated). `$closeStart` is the byte position of the first `>` of the closing `>>`.

- [x] **Step 1: Write the failing tests**

Add to `src/Test/Unit/Model/Pdf/Xref/DictFieldsTest.php` (append inside the class, before the final `}`):

```php
    public function testExtractNameReadsSimpleValue(): void
    {
        self::assertSame('Page', DictFields::extractName('/Type /Page /Parent 2 0 R', 'Type'));
    }

    public function testExtractNameReturnsNullWhenAbsent(): void
    {
        self::assertNull(DictFields::extractName('/Parent 2 0 R', 'Type'));
    }

    public function testExtractRefArrayReadsMultipleReferences(): void
    {
        self::assertSame(
            ['3 0 R', '4 0 R', '5 0 R'],
            DictFields::extractRefArray('/Type /Pages /Kids [3 0 R 4 0 R 5 0 R] /Count 3', 'Kids')
        );
    }

    public function testExtractRefArrayReturnsNullWhenAbsent(): void
    {
        self::assertNull(DictFields::extractRefArray('/Type /Pages /Count 0', 'Kids'));
    }

    public function testExtractRefArrayReturnsEmptyArrayWhenBracketsEmpty(): void
    {
        self::assertSame([], DictFields::extractRefArray('/Kids [] /Count 0', 'Kids'));
    }

    public function testExtractBalancedDictReadsFlatDict(): void
    {
        $text = '<</A 1 /B 2>>';

        [$content, $closeStart] = DictFields::extractBalancedDict($text, 2);

        self::assertSame('/A 1 /B 2', $content);
        self::assertSame('>>', substr($text, $closeStart, 2));
    }

    public function testExtractBalancedDictHandlesNestedDict(): void
    {
        $text = '/Resources <</Font <</F1 3 0 R>> /ProcSet [/PDF /Text]>> /MediaBox [0 0 612 792]';
        $openPos = strpos($text, '<<');

        [$content, $closeStart] = DictFields::extractBalancedDict($text, $openPos + 2);

        self::assertSame('/Font <</F1 3 0 R>> /ProcSet [/PDF /Text]', $content);
        self::assertSame(' /MediaBox [0 0 612 792]', substr($text, $closeStart + 2));
    }

    public function testExtractBalancedDictThrowsWhenUnterminated(): void
    {
        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);

        DictFields::extractBalancedDict('<</A 1 /B 2', 2);
    }
```

- [x] **Step 2: Run tests to verify they fail**

Run: `cd /home/nino/PhpstormProjects/mage-os-module-firma-digitale && vendor/bin/phpunit --filter DictFieldsTest`
Expected: FAIL — `Call to undefined method DictFields::extractName()` (and similarly for the other two new methods).

- [x] **Step 3: Implement the three new methods**

In `src/Model/Pdf/Xref/DictFields.php`, add `use Magento\Framework\Exception\LocalizedException;` after the `namespace` line, then add these three public static methods inside the `DictFields` class (after `hasKey()`, before the closing `}`):

```php
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
```

- [x] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter DictFieldsTest`
Expected: PASS, all tests including the pre-existing ones.

- [x] **Step 5: Commit**

```bash
cd /home/nino/PhpstormProjects/mage-os-module-firma-digitale
git add src/Model/Pdf/Xref/DictFields.php src/Test/Unit/Model/Pdf/Xref/DictFieldsTest.php
git commit -m "Aggiunge extractName/extractRefArray/extractBalancedDict a DictFields"
```

---

### Task 2: `ClassicXrefReader` — espone la tabella offset per oggetto

**Files:**
- Modify: `src/Model/Pdf/Xref/XrefLink.php`
- Modify: `src/Model/Pdf/Xref/ClassicXrefReader.php`
- Test: `src/Test/Unit/Model/Pdf/Xref/ClassicXrefReaderTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `XrefLink::$objectOffsets` (`array<int,int>`, numero oggetto => offset byte), nuovo parametro nominale nel costruttore di `XrefLink` con default `[]`. `ClassicXrefReader::readAt()` lo popola parsando le sottosezioni `xref` (non solo il trailer).

- [x] **Step 1: Write the failing tests**

Add to `src/Test/Unit/Model/Pdf/Xref/ClassicXrefReaderTest.php` (append inside the class, before the final `}`):

```php
    public function testExposesObjectOffsets(): void
    {
        $pdf = "%PDF-1.4\n"
            . "xref\n0 3\n"
            . "0000000000 65535 f \n"
            . "0000000015 00000 n \n"
            . "0000000074 00000 n \n"
            . "trailer\n<</Size 3 /Root 1 0 R>>\nstartxref\n9\n%%EOF\n";
        $offset = strpos($pdf, 'xref');

        $link = $this->reader->readAt($pdf, $offset);

        self::assertSame([1 => 15, 2 => 74], $link->objectOffsets);
    }

    public function testObjectOffsetsSkipsFreeEntries(): void
    {
        $pdf = "xref\n0 2\n"
            . "0000000000 65535 f \n"
            . "0000000015 00000 n \n"
            . "trailer\n<</Size 2 /Root 1 0 R>>\nstartxref\n0\n%%EOF\n";

        $link = $this->reader->readAt($pdf, 0);

        self::assertSame([1 => 15], $link->objectOffsets);
        self::assertArrayNotHasKey(0, $link->objectOffsets);
    }

    public function testObjectOffsetsHandlesMultipleSubsections(): void
    {
        $pdf = "xref\n"
            . "0 1\n0000000000 65535 f \n"
            . "3 2\n0000000200 00000 n \n0000000350 00000 n \n"
            . "trailer\n<</Size 5 /Root 1 0 R>>\nstartxref\n0\n%%EOF\n";

        $link = $this->reader->readAt($pdf, 0);

        self::assertSame([3 => 200, 4 => 350], $link->objectOffsets);
    }
```

- [x] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter ClassicXrefReaderTest`
Expected: FAIL — `Undefined property: MageOS\DigitalSignature\Model\Pdf\Xref\XrefLink::$objectOffsets` (or similar, since the constructor doesn't accept/store it yet).

- [x] **Step 3: Add `$objectOffsets` to `XrefLink`**

Replace the full content of `src/Model/Pdf/Xref/XrefLink.php` with:

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
    /**
     * @param array<int, int> $objectOffsets numero oggetto => offset byte nel file,
     *        solo per gli oggetti "in use" di questa sezione (non l'intera catena)
     */
    public function __construct(
        public readonly int $size,
        public readonly string $root,
        public readonly bool $hasEncrypt,
        public readonly bool $hasObjectStreams,
        public readonly bool $isStream,
        public readonly ?int $prevOffset,
        public readonly array $objectOffsets = []
    ) {
    }
}
```

- [x] **Step 4: Parse object offsets in `ClassicXrefReader`**

Replace the full content of `src/Model/Pdf/Xref/ClassicXrefReader.php` with:

```php
<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Pdf\Xref;

use Magento\Framework\Exception\LocalizedException;

/**
 * Legge una sezione di cross-reference classica (tabella testuale + trailer)
 * a partire da un offset noto: Size/Root/Encrypt/Prev del trailer associato,
 * e la tabella "numero oggetto => offset" delle sole entry "in use" (serve a
 * PageTreeResolver per risolvere oggetti per numero; TagReplacer continua a
 * ritrovare i propri oggetti con una scansione diretta del testo).
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

        $trailerPos = strpos($pdf, 'trailer', $offset);
        $body = substr($pdf, $offset + 4, $trailerPos - $offset - 4);

        return new XrefLink(
            size: $size,
            root: $root,
            hasEncrypt: DictFields::hasKey($dict, 'Encrypt'),
            hasObjectStreams: false,
            isStream: false,
            prevOffset: DictFields::extractInt($dict, 'Prev'),
            objectOffsets: $this->parseObjectOffsets($body)
        );
    }

    /**
     * @return array<int, int> numero oggetto => offset, solo entry "n" (in use)
     */
    private function parseObjectOffsets(string $body): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($body)) ?: [];
        $offsets = [];
        $count = count($lines);
        $i = 0;
        while ($i < $count) {
            $line = trim($lines[$i]);
            $i++;
            if ($line === '' || !preg_match('/^(\d+)\s+(\d+)$/', $line, $header)) {
                continue;
            }
            $start = (int)$header[1];
            $entryCount = (int)$header[2];
            for ($k = 0; $k < $entryCount && $i < $count; $k++, $i++) {
                $entry = trim($lines[$i]);
                if (!preg_match('/^(\d{10})\s+(\d{5})\s+([nf])/', $entry, $em)) {
                    continue;
                }
                if ($em[3] === 'n') {
                    $offsets[$start + $k] = (int)$em[1];
                }
            }
        }

        return $offsets;
    }
}
```

- [x] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter ClassicXrefReaderTest`
Expected: PASS, all tests including the pre-existing ones (`testReadsTrailerFields`, `testReadsPrevAndEncrypt`, `testThrowsWhenNotAtXrefKeyword`, `testThrowsWhenTrailerMissingSizeOrRoot`).

Also run the full suite to catch any other consumer of `XrefLink`'s constructor:

Run: `vendor/bin/phpunit`
Expected: PASS (100 tests before this task; same count, all green — `XrefStreamReader` still constructs `XrefLink` with named args, unaffected by the new optional param).

- [x] **Step 6: Commit**

```bash
git add src/Model/Pdf/Xref/XrefLink.php src/Model/Pdf/Xref/ClassicXrefReader.php src/Test/Unit/Model/Pdf/Xref/ClassicXrefReaderTest.php
git commit -m "ClassicXrefReader espone la tabella offset per oggetto"
```

---

### Task 3: `XrefStreamReader` — espone la tabella offset per oggetto

**Files:**
- Modify: `src/Model/Pdf/Xref/XrefStreamReader.php`
- Test: `src/Test/Unit/Model/Pdf/Xref/XrefStreamReaderTest.php`

**Interfaces:**
- Consumes: `XrefLink` constructor (Task 2), `XrefStreamFixtureTrait::buildXrefStreamRows()` (esistente).
- Produces: `XrefStreamReader::readAt()` popola `XrefLink::$objectOffsets` mappando le righe decodificate di tipo 1 (offset diretto) ai numeri oggetto reali secondo `/Index`; le righe di tipo 2 (dentro un object stream) restano escluse dalla mappa, `hasObjectStreams` continua a funzionare come oggi.

- [x] **Step 1: Write the failing test**

Add to `src/Test/Unit/Model/Pdf/Xref/XrefStreamReaderTest.php` (append inside the class, before the final `}`):

```php
    public function testExposesObjectOffsetsMappedFromIndex(): void
    {
        $rows = $this->buildXrefStreamRows([[1, 100, 0], [1, 200, 0]], 1, 4, 2);
        $pdf = $this->buildXrefStreamPdf('/Type /XRef /Size 3 /Root 2 0 R /W [1 4 2] /Index [1 2]', $rows);

        $link = $this->reader->readAt($pdf, strpos($pdf, '1 0 obj'));

        self::assertSame([1 => 100, 2 => 200], $link->objectOffsets);
    }

    public function testObjectOffsetsSkipObjectStreamEntries(): void
    {
        // riga 1: oggetto 1 con offset diretto; riga 2: oggetto 2 dentro un object stream (tipo 2)
        $rows = $this->buildXrefStreamRows([[1, 100, 0], [2, 5, 0]], 1, 4, 2);
        $pdf = $this->buildXrefStreamPdf('/Type /XRef /Size 3 /Root 2 0 R /W [1 4 2] /Index [1 2]', $rows);

        $link = $this->reader->readAt($pdf, strpos($pdf, '1 0 obj'));

        self::assertSame([1 => 100], $link->objectOffsets);
        self::assertArrayNotHasKey(2, $link->objectOffsets);
    }

    public function testObjectOffsetsMapsMultipleIndexRangesToCorrectNumbers(): void
    {
        $rows = $this->buildXrefStreamRows([[1, 100, 0], [1, 200, 0], [1, 300, 0]], 1, 4, 2);
        $pdf = $this->buildXrefStreamPdf('/Type /XRef /Size 10 /Root 2 0 R /W [1 4 2] /Index [1 1 5 2]', $rows);

        $link = $this->reader->readAt($pdf, strpos($pdf, '1 0 obj'));

        self::assertSame([1 => 100, 5 => 200, 6 => 300], $link->objectOffsets);
    }
```

- [x] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter XrefStreamReaderTest`
Expected: FAIL — `objectOffsets` empty array returned (default), assertions comparing against non-empty expected arrays fail.

- [x] **Step 3: Implement**

In `src/Model/Pdf/Xref/XrefStreamReader.php`, replace this block inside `readAt()`:

```php
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
```

with:

```php
        $hasObjectStreams = false;
        $objectOffsets = [];
        $rowIndex = 0;
        for ($i = 0; $i < count($index); $i += 2) {
            $startNumber = $index[$i];
            $rangeCount = $index[$i + 1];
            for ($k = 0; $k < $rangeCount; $k++) {
                if (!isset($rows[$rowIndex])) {
                    break;
                }
                [$type, $field2] = $rows[$rowIndex];
                if ($type === 2) {
                    $hasObjectStreams = true;
                } elseif ($type === 1) {
                    $objectOffsets[$startNumber + $k] = $field2;
                }
                $rowIndex++;
            }
        }

        return new XrefLink(
            size: $size,
            root: $root,
            hasEncrypt: DictFields::hasKey($dict, 'Encrypt'),
            hasObjectStreams: $hasObjectStreams,
            isStream: true,
            prevOffset: DictFields::extractInt($dict, 'Prev'),
            objectOffsets: $objectOffsets
        );
    }
```

- [x] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter XrefStreamReaderTest`
Expected: PASS, all tests including the pre-existing ones (`testDetectsObjectStreamEntries`, `testReadsMultipleIndexRanges`, etc.).

Run the full suite too:

Run: `vendor/bin/phpunit`
Expected: PASS, all green.

- [x] **Step 5: Commit**

```bash
git add src/Model/Pdf/Xref/XrefStreamReader.php src/Test/Unit/Model/Pdf/Xref/XrefStreamReaderTest.php
git commit -m "XrefStreamReader espone la tabella offset per oggetto"
```

---

### Task 4: `XrefChainResolver` — aggrega la tabella offset sull'intera catena

**Files:**
- Modify: `src/Model/Pdf/Xref/XrefInfo.php`
- Modify: `src/Model/Pdf/Xref/XrefChainResolver.php`
- Test: `src/Test/Unit/Model/Pdf/Xref/XrefChainResolverTest.php`

**Interfaces:**
- Consumes: `XrefLink::$objectOffsets` (Task 2, 3).
- Produces: `XrefInfo::$objectOffsets` (`array<int,int>`), fuso su tutta la catena `/Prev` esplorata (fino a `MAX_CHAIN_DEPTH`): la revisione più recente vince per uno stesso numero oggetto.

- [x] **Step 1: Write the failing test**

Add to `src/Test/Unit/Model/Pdf/Xref/XrefChainResolverTest.php` (append inside the class, before the final `}`):

```php
    public function testExposesObjectOffsetsFromCurrentRevision(): void
    {
        $pdf = "%PDF-1.4\n"
            . "xref\n0 2\n0000000000 65535 f \n0000000042 00000 n \n"
            . "trailer\n<</Size 2 /Root 1 0 R>>\nstartxref\n9\n%%EOF\n";

        $info = $this->resolver->resolve($pdf);

        self::assertSame([1 => 42], $info->objectOffsets);
    }

    public function testMergesObjectOffsetsAcrossPrevChainWithNewestWinning(): void
    {
        // Revisione precedente: oggetto 1 all'offset 42, oggetto 2 all'offset 99.
        $prev = "xref\n0 3\n0000000000 65535 f \n0000000042 00000 n \n0000000099 00000 n \n"
            . "trailer\n<</Size 3 /Root 1 0 R>>\n";
        $prevOffset = 0;
        // Revisione corrente: oggetto 1 aggiornato all'offset 500 (l'oggetto 2 resta
        // valido solo nella revisione precedente e deve comunque comparire nella mappa fusa).
        $current = "xref\n0 1\n0000000000 65535 f \n"
            . "1 1\n0000000500 00000 n \n"
            . "trailer\n<</Size 3 /Root 1 0 R /Prev {$prevOffset}>>\n";
        $currentOffset = strlen($prev);
        $pdf = $prev . $current . "startxref\n{$currentOffset}\n%%EOF\n";

        $info = $this->resolver->resolve($pdf);

        self::assertSame([1 => 500, 2 => 99], $info->objectOffsets);
    }
```

- [x] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter XrefChainResolverTest`
Expected: FAIL — `Undefined property: ... XrefInfo::$objectOffsets`.

- [x] **Step 3: Add `$objectOffsets` to `XrefInfo`**

Replace the full content of `src/Model/Pdf/Xref/XrefInfo.php` with:

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
    /**
     * @param array<int, int> $objectOffsets numero oggetto => offset byte, fuso su
     *        tutta la catena esplorata (la revisione più recente vince)
     */
    public function __construct(
        public readonly int $size,
        public readonly string $root,
        public readonly bool $hasObjectStreams,
        public readonly bool $isEncrypted,
        public readonly bool $isStreamBased,
        public readonly array $objectOffsets = []
    ) {
    }
}
```

- [x] **Step 4: Merge object offsets in `XrefChainResolver::resolve()`**

Replace the `resolve()` method body in `src/Model/Pdf/Xref/XrefChainResolver.php` (keep `readLinkAt()` and the class declaration unchanged) with:

```php
    /**
     * @throws LocalizedException
     */
    public function resolve(string $pdf): XrefInfo
    {
        $offset = StartxrefLocator::locate($pdf);
        $current = $this->readLinkAt($pdf, $offset);

        $hasObjectStreams = $current->hasObjectStreams;
        $hasEncrypt = $current->hasEncrypt;
        $objectOffsets = $current->objectOffsets;
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
            // "+=" su array preserva le chiavi già presenti a sinistra: la
            // revisione più recente (già in $objectOffsets) vince sempre.
            $objectOffsets += $link->objectOffsets;
            $prevOffset = $link->prevOffset;
            $depth++;
        }

        return new XrefInfo(
            size: $current->size,
            root: $current->root,
            hasObjectStreams: $hasObjectStreams,
            isEncrypted: $hasEncrypt,
            isStreamBased: $current->isStream,
            objectOffsets: $objectOffsets
        );
    }
```

- [x] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter XrefChainResolverTest`
Expected: PASS, all tests including the pre-existing ones.

Run: `vendor/bin/phpunit`
Expected: PASS, all green (no other consumer relies on `XrefInfo`'s exact constructor argument count since all existing call sites use named arguments).

- [x] **Step 6: Commit**

```bash
git add src/Model/Pdf/Xref/XrefInfo.php src/Model/Pdf/Xref/XrefChainResolver.php src/Test/Unit/Model/Pdf/Xref/XrefChainResolverTest.php
git commit -m "XrefChainResolver fonde la tabella offset per oggetto sulla catena"
```

---

### Task 5: `PageTreeResolver` — risoluzione albero pagine (solo alberi piatti)

**Files:**
- Create: `src/Model/Pdf/Xref/PageInfo.php`
- Create: `src/Model/Pdf/Xref/PageTreeResolver.php`
- Create: `src/Test/Unit/Model/Pdf/Xref/PdfObjectFixtureTrait.php`
- Create: `src/Test/Unit/Model/Pdf/Xref/PageTreeResolverTest.php`

**Interfaces:**
- Consumes: `XrefChainResolver::resolve(string $pdf): XrefInfo` (con `$objectOffsets`, Task 4), `DictFields::extractRef/extractRefArray/extractName/extractInt/extractBalancedDict` (Task 1 + esistenti).
- Produces: `PageInfo` (DTO, tutti i campi `readonly`), `PageTreeResolver::resolve(string $pdf, int $pageNumber): PageInfo` (`$pageNumber` 1-based), lancia `LocalizedException` per: pagina fuori range, `/Pages` annidato, `/Rotate != 0`, `/Resources` assente o indiretto, `/Contents` assente o non risolvibile.

- [x] **Step 1: Create the PDF fixture-building trait used by this and later tests**

Create `src/Test/Unit/Model/Pdf/Xref/PdfObjectFixtureTrait.php`:

```php
<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Pdf\Xref;

/**
 * Costruisce un PDF con xref classica a partire da un elenco di oggetti già
 * pronti (testo completo "N G obj ... endobj"), usato dai test che devono
 * costruire un albero pagine reale (Catalog/Pages/Page/Contents) senza dover
 * calcolare offset a mano.
 */
trait PdfObjectFixtureTrait
{
    /**
     * @param array<int, string> $objects numero oggetto => testo completo dell'oggetto
     */
    private function buildClassicXrefPdf(array $objects, int $rootNumber): string
    {
        $body = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $number => $text) {
            $offsets[$number] = strlen($body);
            $body .= $text;
        }

        $size = max(array_keys($offsets)) + 1;
        $xrefPos = strlen($body);
        $xref = "xref\n0 {$size}\n";
        for ($n = 0; $n < $size; $n++) {
            if ($n === 0) {
                $xref .= "0000000000 65535 f \n";
                continue;
            }
            $offset = $offsets[$n] ?? 0;
            $xref .= sprintf("%010d 00000 n \n", $offset);
        }
        $trailer = sprintf(
            "trailer\n<</Size %d /Root %d 0 R>>\nstartxref\n%d\n%%%%EOF\n",
            $size,
            $rootNumber,
            $xrefPos
        );

        return $body . $xref . $trailer;
    }
}
```

- [x] **Step 2: Write the failing tests**

Create `src/Test/Unit/Model/Pdf/Xref/PageTreeResolverTest.php`:

```php
<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Pdf\Xref;

use MageOS\DigitalSignature\Model\Pdf\Xref\ClassicXrefReader;
use MageOS\DigitalSignature\Model\Pdf\Xref\PageTreeResolver;
use MageOS\DigitalSignature\Model\Pdf\Xref\XrefChainResolver;
use MageOS\DigitalSignature\Model\Pdf\Xref\XrefStreamReader;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class PageTreeResolverTest extends TestCase
{
    use PdfObjectFixtureTrait;

    private PageTreeResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new PageTreeResolver(
            new XrefChainResolver(new ClassicXrefReader(), new XrefStreamReader())
        );
    }

    private function contentStreamObject(int $number, string $text): string
    {
        return "{$number} 0 obj\n<</Length " . strlen($text) . ">>\nstream\n{$text}\nendstream\nendobj\n";
    }

    public function testResolvesSinglePageWithExistingFont(): void
    {
        $objects = [
            1 => "1 0 obj\n<</Type /Catalog /Pages 2 0 R>>\nendobj\n",
            2 => "2 0 obj\n<</Type /Pages /Kids [3 0 R] /Count 1>>\nendobj\n",
            3 => "3 0 obj\n<</Type /Page /Parent 2 0 R /Contents 4 0 R "
                . "/Resources <</Font <</F1 5 0 R>> /ProcSet [/PDF /Text]>>>>\nendobj\n",
            4 => $this->contentStreamObject(4, 'BT ET'),
            5 => "5 0 obj\n<</Type /Font /Subtype /Type1 /BaseFont /Helvetica>>\nendobj\n",
        ];
        $pdf = $this->buildClassicXrefPdf($objects, 1);

        $page = $this->resolver->resolve($pdf, 1);

        self::assertSame(3, $page->pageObjectNumber);
        self::assertSame(4, $page->contentObjectNumber);
        self::assertSame('F1', $page->existingFontResourceName);
        self::assertSame(6, $page->xrefSize);
    }

    public function testResolvesSecondOfMultiplePages(): void
    {
        $objects = [
            1 => "1 0 obj\n<</Type /Catalog /Pages 2 0 R>>\nendobj\n",
            2 => "2 0 obj\n<</Type /Pages /Kids [3 0 R 4 0 R] /Count 2>>\nendobj\n",
            3 => "3 0 obj\n<</Type /Page /Parent 2 0 R /Contents 5 0 R "
                . "/Resources <</ProcSet [/PDF]>>>>\nendobj\n",
            4 => "4 0 obj\n<</Type /Page /Parent 2 0 R /Contents 6 0 R "
                . "/Resources <</ProcSet [/PDF]>>>>\nendobj\n",
            5 => $this->contentStreamObject(5, 'page one'),
            6 => $this->contentStreamObject(6, 'page two'),
        ];
        $pdf = $this->buildClassicXrefPdf($objects, 1);

        $page = $this->resolver->resolve($pdf, 2);

        self::assertSame(4, $page->pageObjectNumber);
        self::assertSame(6, $page->contentObjectNumber);
        self::assertNull($page->existingFontResourceName);
    }

    public function testRejectsPageNumberOutOfRange(): void
    {
        $objects = [
            1 => "1 0 obj\n<</Type /Catalog /Pages 2 0 R>>\nendobj\n",
            2 => "2 0 obj\n<</Type /Pages /Kids [3 0 R] /Count 1>>\nendobj\n",
            3 => "3 0 obj\n<</Type /Page /Parent 2 0 R /Contents 4 0 R /Resources <<>>>>\nendobj\n",
            4 => $this->contentStreamObject(4, 'x'),
        ];
        $pdf = $this->buildClassicXrefPdf($objects, 1);

        $this->expectException(LocalizedException::class);

        $this->resolver->resolve($pdf, 2);
    }

    public function testRejectsNestedPagesTree(): void
    {
        $objects = [
            1 => "1 0 obj\n<</Type /Catalog /Pages 2 0 R>>\nendobj\n",
            2 => "2 0 obj\n<</Type /Pages /Kids [3 0 R] /Count 1>>\nendobj\n",
            // Il kid è a sua volta un nodo /Pages, non una /Page diretta.
            3 => "3 0 obj\n<</Type /Pages /Kids [4 0 R] /Count 1>>\nendobj\n",
            4 => "4 0 obj\n<</Type /Page /Parent 3 0 R /Contents 5 0 R /Resources <<>>>>\nendobj\n",
            5 => $this->contentStreamObject(5, 'x'),
        ];
        $pdf = $this->buildClassicXrefPdf($objects, 1);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/annidat/');

        $this->resolver->resolve($pdf, 1);
    }

    public function testRejectsRotatedPage(): void
    {
        $objects = [
            1 => "1 0 obj\n<</Type /Catalog /Pages 2 0 R>>\nendobj\n",
            2 => "2 0 obj\n<</Type /Pages /Kids [3 0 R] /Count 1>>\nendobj\n",
            3 => "3 0 obj\n<</Type /Page /Parent 2 0 R /Rotate 90 /Contents 4 0 R "
                . "/Resources <<>>>>\nendobj\n",
            4 => $this->contentStreamObject(4, 'x'),
        ];
        $pdf = $this->buildClassicXrefPdf($objects, 1);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/ruotata/');

        $this->resolver->resolve($pdf, 1);
    }

    public function testRejectsIndirectResources(): void
    {
        $objects = [
            1 => "1 0 obj\n<</Type /Catalog /Pages 2 0 R>>\nendobj\n",
            2 => "2 0 obj\n<</Type /Pages /Kids [3 0 R] /Count 1>>\nendobj\n",
            3 => "3 0 obj\n<</Type /Page /Parent 2 0 R /Contents 4 0 R /Resources 5 0 R>>\nendobj\n",
            4 => $this->contentStreamObject(4, 'x'),
            5 => "5 0 obj\n<</ProcSet [/PDF]>>\nendobj\n",
        ];
        $pdf = $this->buildClassicXrefPdf($objects, 1);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/indirett|Resources/');

        $this->resolver->resolve($pdf, 1);
    }

    public function testRejectsMissingResources(): void
    {
        $objects = [
            1 => "1 0 obj\n<</Type /Catalog /Pages 2 0 R>>\nendobj\n",
            2 => "2 0 obj\n<</Type /Pages /Kids [3 0 R] /Count 1>>\nendobj\n",
            3 => "3 0 obj\n<</Type /Page /Parent 2 0 R /Contents 4 0 R>>\nendobj\n",
            4 => $this->contentStreamObject(4, 'x'),
        ];
        $pdf = $this->buildClassicXrefPdf($objects, 1);

        $this->expectException(LocalizedException::class);

        $this->resolver->resolve($pdf, 1);
    }
```

Close the test class with a final `}` after the last test method.

- [x] **Step 3: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter PageTreeResolverTest`
Expected: FAIL — class `PageTreeResolver` not found.

- [x] **Step 4: Create `PageInfo` DTO**

Create `src/Model/Pdf/Xref/PageInfo.php`:

```php
<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Pdf\Xref;

/**
 * Esito della risoluzione di una pagina tramite PageTreeResolver: identifica
 * l'oggetto Page e il suo content stream, ed espone quanto serve a
 * SignatureTagInjector per decidere se riusare un font esistente o
 * aggiungerne uno nuovo.
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
        public readonly int $xrefSize
    ) {
    }
}
```

- [x] **Step 5: Create `PageTreeResolver`**

Create `src/Model/Pdf/Xref/PageTreeResolver.php`:

```php
<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Pdf\Xref;

use Magento\Framework\Exception\LocalizedException;

/**
 * Risolve una pagina per numero (1-based) risalendo Root -> /Pages ->
 * /Kids[N] -> oggetto Page, usando la tabella "numero oggetto => offset"
 * esposta da XrefChainResolver. Supporta solo alberi /Pages "piatti" (Kids
 * che referenzia direttamente le pagine): un nodo /Pages annidato, una
 * pagina con /Rotate diverso da zero, o /Resources assente/indiretto sono
 * rifiutati esplicitamente, mai gestiti silenziosamente.
 */
final class PageTreeResolver
{
    public function __construct(private readonly XrefChainResolver $xrefResolver)
    {
    }

    /**
     * @throws LocalizedException
     */
    public function resolve(string $pdf, int $pageNumber): PageInfo
    {
        $info = $this->xrefResolver->resolve($pdf);

        $root = $this->readObjectDict($pdf, $info->objectOffsets, $this->refToObjectNumber($info->root));
        $pagesRef = DictFields::extractRef($root['dict'], 'Pages');
        if ($pagesRef === null) {
            throw new LocalizedException(__('PDF non supportato: catalogo privo di /Pages.'));
        }

        $pages = $this->readObjectDict($pdf, $info->objectOffsets, $this->refToObjectNumber($pagesRef));
        $kids = DictFields::extractRefArray($pages['dict'], 'Kids');
        if ($kids === null || $kids === []) {
            throw new LocalizedException(__('PDF non supportato: /Pages privo di /Kids.'));
        }

        if ($pageNumber < 1 || $pageNumber > count($kids)) {
            throw new LocalizedException(
                __('Numero di pagina %1 non valido: il PDF ne ha %2.', $pageNumber, count($kids))
            );
        }

        $pageObjNumber = $this->refToObjectNumber($kids[$pageNumber - 1]);
        $page = $this->readObjectDict($pdf, $info->objectOffsets, $pageObjNumber);

        if (DictFields::extractName($page['dict'], 'Type') === 'Pages') {
            throw new LocalizedException(
                __('PDF non supportato: struttura pagine annidata non gestita dal builder.')
            );
        }

        $rotate = DictFields::extractInt($page['dict'], 'Rotate') ?? 0;
        if ($rotate !== 0) {
            throw new LocalizedException(
                __('PDF non supportato: pagina ruotata (/Rotate %1) non gestita dal builder.', $rotate)
            );
        }

        if (DictFields::extractRef($page['dict'], 'Resources') !== null) {
            throw new LocalizedException(
                __('PDF non supportato: /Resources come riferimento indiretto non gestito dal builder.')
            );
        }
        if (!preg_match('/\/Resources\s*<</', $page['dict'], $resMatch, PREG_OFFSET_CAPTURE)) {
            throw new LocalizedException(
                __('PDF non supportato: pagina priva di /Resources dichiarate direttamente.')
            );
        }
        $resourcesContentStart = $resMatch[0][1] + strlen($resMatch[0][0]);
        [$resourcesDict] = DictFields::extractBalancedDict($page['dict'], $resourcesContentStart);

        $existingFontName = null;
        if (preg_match('/\/Font\s*<</', $resourcesDict, $fontMatch, PREG_OFFSET_CAPTURE)) {
            $fontContentStart = $fontMatch[0][1] + strlen($fontMatch[0][0]);
            [$fontDict] = DictFields::extractBalancedDict($resourcesDict, $fontContentStart);
            if (preg_match('/\/(\w+)\s+\d+\s+\d+\s+R/', $fontDict, $firstFont)) {
                $existingFontName = $firstFont[1];
            }
        }

        $contentsRef = DictFields::extractRef($page['dict'], 'Contents');
        if ($contentsRef === null) {
            throw new LocalizedException(__('PDF non supportato: pagina priva di /Contents.'));
        }
        $contentsNumber = $this->refToObjectNumber($contentsRef);
        if (!isset($info->objectOffsets[$contentsNumber])) {
            throw new LocalizedException(
                __('PDF non supportato: content stream della pagina non risolvibile.')
            );
        }

        return new PageInfo(
            pageObjectNumber: $pageObjNumber,
            pageObjectOffset: $page['offset'],
            pageObjectLength: $page['length'],
            pageDictText: $page['dict'],
            contentObjectNumber: $contentsNumber,
            contentObjectOffset: $info->objectOffsets[$contentsNumber],
            existingFontResourceName: $existingFontName,
            xrefSize: $info->size
        );
    }

    /**
     * @param array<int, int> $objectOffsets
     * @return array{dict: string, offset: int, length: int}
     * @throws LocalizedException
     */
    private function readObjectDict(string $pdf, array $objectOffsets, int $objectNumber): array
    {
        if (!isset($objectOffsets[$objectNumber])) {
            throw new LocalizedException(
                __('PDF non supportato: oggetto %1 non risolvibile nella tabella cross-reference.', $objectNumber)
            );
        }
        $offset = $objectOffsets[$objectNumber];
        $region = substr($pdf, $offset);
        if (!preg_match('/^(\d+)\s+(\d+)\s+obj\s*<</', $region, $header)) {
            throw new LocalizedException(
                __('PDF non supportato: oggetto %1 non riconosciuto alla posizione attesa.', $objectNumber)
            );
        }
        $dictContentStart = strlen($header[0]);
        [$dict, $closeStart] = DictFields::extractBalancedDict($region, $dictContentStart);

        $endobjPos = strpos($region, 'endobj', $closeStart + 2);
        if ($endobjPos === false) {
            throw new LocalizedException(
                __('PDF non supportato: oggetto %1 privo di endobj.', $objectNumber)
            );
        }

        return [
            'dict' => $dict,
            'offset' => $offset,
            'length' => $endobjPos + strlen('endobj') - 0,
        ];
    }

    private function refToObjectNumber(string $ref): int
    {
        [$number] = explode(' ', trim($ref), 2);

        return (int)$number;
    }
}
```

- [x] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter PageTreeResolverTest`
Expected: PASS, all 7 tests.

Run: `vendor/bin/phpunit`
Expected: PASS, all green.

- [x] **Step 7: Commit**

```bash
git add src/Model/Pdf/Xref/PageInfo.php src/Model/Pdf/Xref/PageTreeResolver.php \
        src/Test/Unit/Model/Pdf/Xref/PdfObjectFixtureTrait.php src/Test/Unit/Model/Pdf/Xref/PageTreeResolverTest.php
git commit -m "Aggiunge PageTreeResolver (risoluzione albero pagine piatto)"
```

---

### Task 6: Estrae `IncrementalUpdateWriter` da `TagReplacer` (refactor, nessuna regressione)

**Files:**
- Create: `src/Model/Pdf/IncrementalUpdateWriter.php`
- Modify: `src/Model/Pdf/TagReplacer.php`
- Modify: `src/Test/Unit/Model/Pdf/TagReplacerTest.php`
- Modify: `src/Test/Unit/Model/Pdf/TemplateValidatorTest.php`

**Interfaces:**
- Produces: `IncrementalUpdateWriter::__construct(XrefChainResolver $xrefResolver)`, `buildStreamObject(int $number, string $content, bool $compress): string`, `append(string $pdf, string $originalPdf, array $modifiedObjects): string` (stesso comportamento del precedente `TagReplacer::appendIncrementalUpdate`, generalizzato per accettare anche numeri oggetto MAI usati prima, non solo numeri esistenti riscritti — usato da `SignatureTagInjector` nel Task 8).
- Consumes: `TagReplacer::__construct(XrefChainResolver $xrefResolver, IncrementalUpdateWriter $updateWriter)` — **firma cambiata**, aggiorna i due test che istanziano `TagReplacer` direttamente.

- [x] **Step 1: Create `IncrementalUpdateWriter` with the logic moved out of `TagReplacer`**

Create `src/Model/Pdf/IncrementalUpdateWriter.php`:

```php
<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Pdf;

use MageOS\DigitalSignature\Model\Pdf\Xref\StartxrefLocator;
use MageOS\DigitalSignature\Model\Pdf\Xref\XrefChainResolver;
use MageOS\DigitalSignature\Model\Pdf\Xref\XrefInfo;
use Magento\Framework\Exception\LocalizedException;

/**
 * Scrittura di un "incremental update" PDF: appende oggetti nuovi/modificati
 * in coda al file e una nuova sezione cross-reference (tabella classica o
 * xref stream, secondo il formato della revisione più recente del
 * sorgente), collegata alla precedente tramite /Prev. Condiviso tra
 * TagReplacer (sostituzione di oggetti esistenti) e SignatureTagInjector
 * (introduzione di oggetti mai usati prima, es. un font).
 */
class IncrementalUpdateWriter
{
    public function __construct(private readonly XrefChainResolver $xrefResolver)
    {
    }

    public function buildStreamObject(int $number, string $content, bool $compress): string
    {
        $stream = $compress ? gzcompress($content, 9) : $content;
        $dict = $compress
            ? sprintf('<</Filter /FlateDecode /Length %d>>', strlen($stream))
            : sprintf('<</Length %d>>', strlen($stream));

        return sprintf("%d 0 obj\n%s\nstream\n%s\nendstream\nendobj\n", $number, $dict, $stream);
    }

    /**
     * Appende gli oggetti modificati/nuovi con una nuova sezione xref collegata
     * alla precedente (/Prev), secondo il meccanismo di incremental update. Il
     * formato della nuova sezione (tabella classica o xref stream) segue quello
     * della revisione più recente di $originalPdf.
     *
     * @param array<int, array{number: int, body: string}> $modifiedObjects numeri
     *        oggetto esistenti (riscritti) o mai usati prima (nuovi, es. un font)
     * @throws LocalizedException
     */
    public function append(string $pdf, string $originalPdf, array $modifiedObjects): string
    {
        $prevXref = StartxrefLocator::locate($originalPdf);
        $info = $this->xrefResolver->resolve($originalPdf);

        $output = rtrim($pdf, "\n\r") . "\n";
        $offsets = [];
        foreach ($modifiedObjects as $object) {
            $offsets[$object['number']] = strlen($output);
            $output .= $object['body'];
        }

        // Prossimo numero oggetto libero: oltre /Size dichiarato in origine, o
        // oltre il più alto numero già usato in $modifiedObjects (un oggetto
        // nuovo, es. un font, può già occupare esattamente $info->size).
        $usedNumbers = array_map(static fn (array $o): int => $o['number'] + 1, $modifiedObjects);
        $nextFreeNumber = max(array_merge([$info->size], $usedNumbers));

        if ($info->isStreamBased) {
            return $output . $this->buildXrefStreamUpdate($output, $offsets, $info, $prevXref, $nextFreeNumber);
        }

        ksort($offsets);
        $xrefOffset = strlen($output);
        $xref = "xref\n";
        foreach ($offsets as $number => $offset) {
            $xref .= sprintf("%d 1\n%010d 00000 n \n", $number, $offset);
        }
        $newTrailer = sprintf(
            "trailer\n<</Size %d /Root %s /Prev %d>>\nstartxref\n%d\n%%%%EOF\n",
            $nextFreeNumber,
            $info->root,
            $prevXref,
            $xrefOffset
        );

        return $output . $xref . $newTrailer;
    }

    /**
     * @param array<int, int> $offsets numero oggetto => offset nel file
     */
    private function buildXrefStreamUpdate(
        string $outputSoFar,
        array $offsets,
        XrefInfo $info,
        int $prevXref,
        int $nextFreeNumber
    ): string {
        $xrefObjNum = $nextFreeNumber;
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
            $xrefObjNum + 1,
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

- [x] **Step 2: Refactor `TagReplacer` to delegate to `IncrementalUpdateWriter`**

Replace the full content of `src/Model/Pdf/TagReplacer.php` with:

```php
<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Pdf;

use MageOS\DigitalSignature\Model\Pdf\Xref\XrefChainResolver;
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

    public function __construct(
        private readonly XrefChainResolver $xrefResolver,
        private readonly IncrementalUpdateWriter $updateWriter
    ) {
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

        // Snapshot pre-sbiancamento: la risoluzione della catena xref deve
        // descrivere la struttura ORIGINALE del documento, non quella con gli
        // oggetti-tag già sbiancati. Normalmente non farebbe differenza (xref
        // e trailer vivono dopo tutti gli oggetti), ma in PDF patologici un
        // oggetto puo' coincidere con la posizione puntata da startxref: se lo
        // sbiancamento avviene prima, il riconoscimento della xref fallirebbe
        // per un motivo diverso (byte a spazi) da quello reale (xref assente).
        $originalPdf = $pdf;

        $modifiedObjects = [];
        foreach ($this->extractStreamObjects($pdf) as $object) {
            if (!preg_match(self::TAG_REGEX, $object['content'])) {
                continue;
            }
            $newContent = preg_replace_callback(self::TAG_REGEX, $replacer, $object['content']);
            $modifiedObjects[] = [
                'number' => $object['number'],
                'body' => $this->updateWriter->buildStreamObject($object['number'], $newContent, $object['compressed']),
            ];
            // Sbianca i byte dell'oggetto originale preservandone la lunghezza
            $pdf = substr_replace($pdf, str_repeat(' ', $object['length']), $object['offset'], $object['length']);
        }
        if (!$modifiedObjects) {
            throw new LocalizedException(
                __('Nessun tag firma trovato nel PDF template: impossibile inserire i dati del firmatario.')
            );
        }

        return $this->updateWriter->append($pdf, $originalPdf, $modifiedObjects);
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
}
```

- [x] **Step 3: Update the two tests that construct `TagReplacer` directly**

In `src/Test/Unit/Model/Pdf/TagReplacerTest.php`, add `use MageOS\DigitalSignature\Model\Pdf\IncrementalUpdateWriter;` to the `use` block, then replace:

```php
    protected function setUp(): void
    {
        $this->tagReplacer = new TagReplacer(
            new XrefChainResolver(new ClassicXrefReader(), new XrefStreamReader())
        );
    }
```

with:

```php
    protected function setUp(): void
    {
        $xrefResolver = new XrefChainResolver(new ClassicXrefReader(), new XrefStreamReader());
        $this->tagReplacer = new TagReplacer($xrefResolver, new IncrementalUpdateWriter($xrefResolver));
    }
```

In `src/Test/Unit/Model/Pdf/TemplateValidatorTest.php`, add `use MageOS\DigitalSignature\Model\Pdf\IncrementalUpdateWriter;` to the `use` block, then replace:

```php
    protected function setUp(): void
    {
        $xrefResolver = new XrefChainResolver(new ClassicXrefReader(), new XrefStreamReader());
        $this->validator = new TemplateValidator(new TagReplacer($xrefResolver), $xrefResolver);
    }
```

with:

```php
    protected function setUp(): void
    {
        $xrefResolver = new XrefChainResolver(new ClassicXrefReader(), new XrefStreamReader());
        $tagReplacer = new TagReplacer($xrefResolver, new IncrementalUpdateWriter($xrefResolver));
        $this->validator = new TemplateValidator($tagReplacer, $xrefResolver);
    }
```

- [x] **Step 4: Run the full suite to confirm zero regressions**

Run: `vendor/bin/phpunit`
Expected: PASS, exact same test/assertion count as before this task (only constructor wiring changed, no assertion changed) — every `TagReplacerTest` and `TemplateValidatorTest` test (including the two real-PDF-fixture tests from the xref-stream work) still green.

- [x] **Step 5: Commit**

```bash
git add src/Model/Pdf/IncrementalUpdateWriter.php src/Model/Pdf/TagReplacer.php \
        src/Test/Unit/Model/Pdf/TagReplacerTest.php src/Test/Unit/Model/Pdf/TemplateValidatorTest.php
git commit -m "Estrae IncrementalUpdateWriter da TagReplacer (nessuna regressione)"
```

---

### Task 7: `SignatureTagInjector` — caso font già presente sulla pagina

**Files:**
- Create: `src/Model/Pdf/SignatureTagInjector.php`
- Create: `src/Test/Unit/Model/Pdf/SignatureTagInjectorTest.php`

**Interfaces:**
- Consumes: `PageTreeResolver::resolve()` (Task 5), `IncrementalUpdateWriter::buildStreamObject()`/`append()` (Task 6).
- Produces: `SignatureTagInjector::__construct(PageTreeResolver $pageResolver, IncrementalUpdateWriter $updateWriter)`, `injectAt(string $pdf, int $pageNumber, float $xPoints, float $yPoints, float $widthMm, float $heightMm): string`, `SignatureTagInjector::PLACEHOLDER_EMAIL` (costante pubblica).

- [x] **Step 1: Write the failing tests (font-reused case only)**

Create `src/Test/Unit/Model/Pdf/SignatureTagInjectorTest.php`:

```php
<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Pdf;

use MageOS\DigitalSignature\Model\Pdf\IncrementalUpdateWriter;
use MageOS\DigitalSignature\Model\Pdf\SignatureTagInjector;
use MageOS\DigitalSignature\Model\Pdf\TagReplacer;
use MageOS\DigitalSignature\Model\Pdf\Xref\ClassicXrefReader;
use MageOS\DigitalSignature\Model\Pdf\Xref\PageTreeResolver;
use MageOS\DigitalSignature\Model\Pdf\Xref\XrefChainResolver;
use MageOS\DigitalSignature\Model\Pdf\Xref\XrefStreamReader;
use MageOS\DigitalSignature\Test\Unit\Model\Pdf\Xref\PdfObjectFixtureTrait;
use PHPUnit\Framework\TestCase;

class SignatureTagInjectorTest extends TestCase
{
    use PdfObjectFixtureTrait;

    private SignatureTagInjector $injector;
    private TagReplacer $tagReplacer;

    protected function setUp(): void
    {
        $xrefResolver = new XrefChainResolver(new ClassicXrefReader(), new XrefStreamReader());
        $updateWriter = new IncrementalUpdateWriter($xrefResolver);
        $this->injector = new SignatureTagInjector(new PageTreeResolver($xrefResolver), $updateWriter);
        $this->tagReplacer = new TagReplacer($xrefResolver, $updateWriter);
    }

    private function contentStreamObject(int $number, string $text): string
    {
        return "{$number} 0 obj\n<</Length " . strlen($text) . ">>\nstream\n{$text}\nendstream\nendobj\n";
    }

    private function pdfWithExistingFont(): string
    {
        $objects = [
            1 => "1 0 obj\n<</Type /Catalog /Pages 2 0 R>>\nendobj\n",
            2 => "2 0 obj\n<</Type /Pages /Kids [3 0 R] /Count 1>>\nendobj\n",
            3 => "3 0 obj\n<</Type /Page /Parent 2 0 R /Contents 4 0 R "
                . "/Resources <</Font <</F1 5 0 R>>>>>>\nendobj\n",
            4 => $this->contentStreamObject(4, 'BT /F1 12 Tf (documento) Tj ET'),
            5 => "5 0 obj\n<</Type /Font /Subtype /Type1 /BaseFont /Helvetica>>\nendobj\n",
        ];

        return $this->buildClassicXrefPdf($objects, 1);
    }

    public function testInjectsFindableTagUsingExistingFont(): void
    {
        $pdf = $this->pdfWithExistingFont();

        $result = $this->injector->injectAt($pdf, 1, 72.0, 700.0, 80.0, 20.0);

        self::assertStringContainsString('%PDF', $result);
        $tags = $this->tagReplacer->findTags($result);
        self::assertCount(1, $tags);
        self::assertStringContainsString('{WSIGN#80,20#' . SignatureTagInjector::PLACEHOLDER_EMAIL, $tags[0]);
    }

    public function testInjectedTagUsesExistingFontResourceName(): void
    {
        $pdf = $this->pdfWithExistingFont();

        $result = $this->injector->injectAt($pdf, 1, 72.0, 700.0, 80.0, 20.0);

        // Nessun nuovo oggetto Font aggiunto: il conteggio degli "obj" resta invariato
        // (5 oggetti originali + 1 content-stream riscritto in incremental update + 1 xref = 7).
        self::assertSame(7, preg_match_all('/\d+\s+0\s+obj/', $result));
        self::assertStringContainsString('/F1', $result);
    }

    public function testInjectedTagSurvivesRealSignerEmailReplacement(): void
    {
        $pdf = $this->pdfWithExistingFont();

        $withTag = $this->injector->injectAt($pdf, 1, 72.0, 700.0, 80.0, 20.0);
        $withEmail = $this->tagReplacer->replaceSignerEmail($withTag, 'cliente.reale@example.com');

        self::assertSame(
            ['{WSIGN#80,20#cliente.reale@example.com}'],
            $this->tagReplacer->findTags($withEmail)
        );
    }

    public function testRejectsUnsupportedPageStructure(): void
    {
        $objects = [
            1 => "1 0 obj\n<</Type /Catalog /Pages 2 0 R>>\nendobj\n",
            2 => "2 0 obj\n<</Type /Pages /Kids [3 0 R] /Count 1>>\nendobj\n",
            3 => "3 0 obj\n<</Type /Page /Parent 2 0 R /Rotate 180 /Contents 4 0 R "
                . "/Resources <</Font <</F1 5 0 R>>>>>>\nendobj\n",
            4 => $this->contentStreamObject(4, 'BT ET'),
            5 => "5 0 obj\n<</Type /Font /Subtype /Type1 /BaseFont /Helvetica>>\nendobj\n",
        ];
        $pdf = $this->buildClassicXrefPdf($objects, 1);

        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);

        $this->injector->injectAt($pdf, 1, 72.0, 700.0, 80.0, 20.0);
    }
}
```

- [x] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter SignatureTagInjectorTest`
Expected: FAIL — class `SignatureTagInjector` not found.

- [x] **Step 3: Implement `SignatureTagInjector` (font-reused path; font-added path added in Task 8)**

Create `src/Model/Pdf/SignatureTagInjector.php`:

```php
<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Pdf;

use MageOS\DigitalSignature\Model\Pdf\Xref\DictFields;
use MageOS\DigitalSignature\Model\Pdf\Xref\PageTreeResolver;
use Magento\Framework\Exception\LocalizedException;

/**
 * Inserisce un tag firma {WSIGN#W,H#email} in un punto preciso di una
 * pagina, scrivendo testo PDF vero e proprio (Tj, con un font reale) tramite
 * lo stesso meccanismo di incremental update già usato da TagReplacer. Se la
 * pagina non ha già un font dichiarato, ne aggiunge uno standard
 * (Helvetica, nessun embedding necessario). Pure PHP, nessuna dipendenza
 * esterna.
 */
class SignatureTagInjector
{
    /**
     * Email segnaposto scritta nel tag appena inserito: sostituita più tardi
     * da TagReplacer::replaceSignerEmail() con l'email reale del firmatario,
     * esattamente come per un tag scritto a mano dal merchant. Distinta da
     * TemplateValidator::PREVIEW_SIGNER_EMAIL, che ha uno scopo diverso
     * (email fissa usata SOLO per il dry-run di validazione/anteprima).
     */
    public const PLACEHOLDER_EMAIL = 'firmatario@da-impostare.invalid';

    private const MAX_DECOMPRESSED_STREAM = 52428800;
    private const NEW_FONT_RESOURCE_NAME = 'MDSHelv1';
    private const FONT_SIZE_PT = 10;

    public function __construct(
        private readonly PageTreeResolver $pageResolver,
        private readonly IncrementalUpdateWriter $updateWriter
    ) {
    }

    /**
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
        $fontName = $page->existingFontResourceName ?? self::NEW_FONT_RESOURCE_NAME;

        $contentObject = $this->readContentStreamObject($pdf, $page->contentObjectOffset, $page->contentObjectNumber);
        $suffix = sprintf(
            "\nq BT /%s %d Tf %s %s Td (%s) Tj ET Q\n",
            $fontName,
            self::FONT_SIZE_PT,
            $this->formatNumber($xPoints),
            $this->formatNumber($yPoints),
            $escapedTag
        );
        $newContentBody = $this->updateWriter->buildStreamObject(
            $contentObject['number'],
            $contentObject['content'] . $suffix,
            $contentObject['compressed']
        );

        $modifiedObjects = [
            ['number' => $contentObject['number'], 'body' => $newContentBody],
        ];
        $pdf = substr_replace(
            $pdf,
            str_repeat(' ', $contentObject['length']),
            $contentObject['offset'],
            $contentObject['length']
        );

        return $this->updateWriter->append($pdf, $originalPdf, $modifiedObjects);
    }

    /**
     * @return array{number: int, content: string, compressed: bool, offset: int, length: int}
     * @throws LocalizedException
     */
    private function readContentStreamObject(string $pdf, int $offset, int $number): array
    {
        $region = substr($pdf, $offset);
        if (!preg_match('/^(\d+)\s+(\d+)\s+obj\b/', $region)) {
            throw new LocalizedException(__('PDF non supportato: content stream non riconosciuto.'));
        }
        $endobjPos = strpos($region, 'endobj');
        if ($endobjPos === false) {
            throw new LocalizedException(__('PDF non supportato: content stream privo di endobj.'));
        }
        $objectRegion = substr($region, 0, $endobjPos + strlen('endobj'));

        if (!preg_match('/>>\s*stream(\r\n|\n)/', $objectRegion, $streamMatch, PREG_OFFSET_CAPTURE)) {
            throw new LocalizedException(__('PDF non supportato: content stream privo di sezione stream.'));
        }
        $dataStart = $streamMatch[0][1] + strlen($streamMatch[0][0]);
        $endstreamPos = strrpos($objectRegion, 'endstream');
        if ($endstreamPos === false || $endstreamPos <= $dataStart) {
            throw new LocalizedException(__('PDF non supportato: content stream privo di endstream.'));
        }
        $dataEnd = $endstreamPos;
        if (substr($objectRegion, $dataEnd - 1, 1) === "\n") {
            $dataEnd--;
            if (substr($objectRegion, $dataEnd - 1, 1) === "\r") {
                $dataEnd--;
            }
        }
        $raw = substr($objectRegion, $dataStart, $dataEnd - $dataStart);
        $dict = substr($objectRegion, 0, $streamMatch[0][1]);
        $compressed = str_contains($dict, '/FlateDecode');

        if ($compressed) {
            // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- warning atteso oltre il cap anti-bomba
            $content = @gzuncompress($raw, self::MAX_DECOMPRESSED_STREAM);
            if ($content === false) {
                throw new LocalizedException(__('PDF non supportato: content stream non decomprimibile.'));
            }
        } else {
            $content = $raw;
        }

        return [
            'number' => $number,
            'content' => $content,
            'compressed' => $compressed,
            'offset' => $offset,
            'length' => strlen($objectRegion),
        ];
    }

    private function formatNumber(float $value): string
    {
        $formatted = rtrim(rtrim(sprintf('%.2F', round($value, 2)), '0'), '.');

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }
}
```

- [x] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter SignatureTagInjectorTest`
Expected: PASS, all 4 tests.

Run: `vendor/bin/phpunit`
Expected: PASS, all green.

- [x] **Step 5: Commit**

```bash
git add src/Model/Pdf/SignatureTagInjector.php src/Test/Unit/Model/Pdf/SignatureTagInjectorTest.php
git commit -m "Aggiunge SignatureTagInjector (caso font gia' presente sulla pagina)"
```

---

### Task 8: `SignatureTagInjector` — caso font assente (aggiunge Helvetica)

**Files:**
- Modify: `src/Model/Pdf/SignatureTagInjector.php`
- Modify: `src/Test/Unit/Model/Pdf/SignatureTagInjectorTest.php`

**Interfaces:**
- Consumes: `DictFields::extractBalancedDict()` (Task 1), `PageInfo::$pageDictText`/`$pageObjectNumber`/`$pageObjectOffset`/`$pageObjectLength`/`$xrefSize` (Task 5).
- Produces: nessuna nuova firma pubblica — `injectAt()` ora gestisce anche il caso `existingFontResourceName === null`, aggiungendo un nuovo oggetto Font e riscrivendo il dizionario `/Resources` della pagina nella stessa incremental update.

- [x] **Step 1: Write the failing tests (font-added case)**

Add to `src/Test/Unit/Model/Pdf/SignatureTagInjectorTest.php` (append inside the class, before the final `}`):

```php
    private function pdfWithoutFont(): string
    {
        $objects = [
            1 => "1 0 obj\n<</Type /Catalog /Pages 2 0 R>>\nendobj\n",
            2 => "2 0 obj\n<</Type /Pages /Kids [3 0 R] /Count 1>>\nendobj\n",
            3 => "3 0 obj\n<</Type /Page /Parent 2 0 R /Contents 4 0 R "
                . "/Resources <</ProcSet [/PDF]>>>>\nendobj\n",
            4 => $this->contentStreamObject(4, 'q 1 0 0 RG 0 0 100 100 re S Q'),
        ];

        return $this->buildClassicXrefPdf($objects, 1);
    }

    public function testInjectsFindableTagAddingNewFontWhenNoneExists(): void
    {
        $pdf = $this->pdfWithoutFont();

        $result = $this->injector->injectAt($pdf, 1, 50.0, 400.0, 60.0, 15.0);

        $tags = $this->tagReplacer->findTags($result);
        self::assertCount(1, $tags);
        self::assertStringContainsString('{WSIGN#60,15#' . SignatureTagInjector::PLACEHOLDER_EMAIL, $tags[0]);
        self::assertStringContainsString('/Type /Font', $result);
        self::assertStringContainsString('/BaseFont /Helvetica', $result);
    }

    public function testAddedFontIsReferencedInRewrittenPageResources(): void
    {
        $pdf = $this->pdfWithoutFont();

        $result = $this->injector->injectAt($pdf, 1, 50.0, 400.0, 60.0, 15.0);

        self::assertStringContainsString('/Font <</MDSHelv1', $result);
    }

    public function testInjectedTagWithNewFontSurvivesRealSignerEmailReplacement(): void
    {
        $pdf = $this->pdfWithoutFont();

        $withTag = $this->injector->injectAt($pdf, 1, 50.0, 400.0, 60.0, 15.0);
        $withEmail = $this->tagReplacer->replaceSignerEmail($withTag, 'cliente.reale@example.com');

        self::assertSame(
            ['{WSIGN#60,15#cliente.reale@example.com}'],
            $this->tagReplacer->findTags($withEmail)
        );
    }
```

- [x] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter SignatureTagInjectorTest`
Expected: FAIL on the 3 new tests — `PDF non supportato: /Resources...` or the tag not found, since the current implementation only ever reuses `$page->existingFontResourceName` and never adds a font (the `pdfWithoutFont()` page has `/Resources <</ProcSet [/PDF]>>`, no `/Font` key, so `existingFontResourceName` is `null` and the current code would reference `/MDSHelv1` in the content stream without ever declaring it as a resource — the tag would still be *findable* by regex, but the two new assertions on `/Type /Font` and `/Font <</MDSHelv1` fail).

- [x] **Step 3: Implement the font-added branch**

In `src/Model/Pdf/SignatureTagInjector.php`, replace the body of `injectAt()` (from `$modifiedObjects = [...]` through `return $this->updateWriter->append(...)`) with:

```php
        $modifiedObjects = [
            ['number' => $contentObject['number'], 'body' => $newContentBody],
        ];
        $pdf = substr_replace(
            $pdf,
            str_repeat(' ', $contentObject['length']),
            $contentObject['offset'],
            $contentObject['length']
        );

        if ($page->existingFontResourceName === null) {
            $fontObjectNumber = $page->xrefSize;
            $modifiedObjects[] = [
                'number' => $fontObjectNumber,
                'body' => sprintf(
                    "%d 0 obj\n<</Type /Font /Subtype /Type1 /BaseFont /Helvetica>>\nendobj\n",
                    $fontObjectNumber
                ),
            ];
            $modifiedObjects[] = [
                'number' => $page->pageObjectNumber,
                'body' => $this->buildPageObjectWithFontResource($page, $fontObjectNumber),
            ];
            $pdf = substr_replace(
                $pdf,
                str_repeat(' ', $page->pageObjectLength),
                $page->pageObjectOffset,
                $page->pageObjectLength
            );
        }

        return $this->updateWriter->append($pdf, $originalPdf, $modifiedObjects);
    }

    private function buildPageObjectWithFontResource(\MageOS\DigitalSignature\Model\Pdf\Xref\PageInfo $page, int $fontObjectNumber): string
    {
        if (!preg_match('/\/Resources\s*<</', $page->pageDictText, $resMatch, PREG_OFFSET_CAPTURE)) {
            // Difensivo: PageTreeResolver ha già garantito la presenza di /Resources inline.
            throw new LocalizedException(__('PDF non supportato: /Resources non ritrovato per l\'aggiornamento.'));
        }
        $resourcesContentStart = $resMatch[0][1] + strlen($resMatch[0][0]);
        [$resourcesContent, $resourcesContentEnd] = DictFields::extractBalancedDict(
            $page->pageDictText,
            $resourcesContentStart
        );
        $newResourcesContent = $resourcesContent
            . sprintf(' /Font <</%s %d 0 R>>', self::NEW_FONT_RESOURCE_NAME, $fontObjectNumber);

        $newPageDictText = substr($page->pageDictText, 0, $resourcesContentStart)
            . $newResourcesContent
            . substr($page->pageDictText, $resourcesContentEnd);

        return sprintf("%d 0 obj\n<<%s>>\nendobj\n", $page->pageObjectNumber, $newPageDictText);
    }
```

Then add the missing import at the top of the file (next to the other `use` statements):

```php
use MageOS\DigitalSignature\Model\Pdf\Xref\PageInfo;
```

and simplify the method signature just added to use the short class name instead of the fully-qualified one:

```php
    private function buildPageObjectWithFontResource(PageInfo $page, int $fontObjectNumber): string
```

- [x] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter SignatureTagInjectorTest`
Expected: PASS, all 7 tests (4 from Task 7 + 3 new).

Run: `vendor/bin/phpunit`
Expected: PASS, all green — full suite (should now be 100 + ~35 new tests from Tasks 1-8 combined, exact count depends on final tallies but must be 100% green, zero failures/errors).

- [x] **Step 5: Commit**

```bash
git add src/Model/Pdf/SignatureTagInjector.php src/Test/Unit/Model/Pdf/SignatureTagInjectorTest.php
git commit -m "SignatureTagInjector: aggiunge un font Helvetica quando la pagina non ne ha uno"
```

---

## Self-Review Notes (already applied above)

- **Spec coverage**: `ClassicXrefReader`/`XrefStreamReader` object-offset extension → Tasks 2-3. `PageTreeResolver` con tutti i rifiuti espliciti (albero annidato, `/Rotate`, `/Resources` indiretto/assente) → Task 5. `IncrementalUpdateWriter` estratto da `TagReplacer` → Task 6. `SignatureTagInjector` con font riusato/aggiunto → Task 7-8. Round-trip (`findTags`/`replaceSignerEmail` sull'output dell'iniettore) → coperto nei test di Task 7 e 8. Fuori scope dello spec (viewer PDF, wiring in `Upload.php`) → non presenti in questo piano, come da spec.
- **Placeholder scan**: nessun TBD/TODO; ogni step ha codice completo.
- **Type consistency**: `PageInfo` (Task 5) è usato identicamente in Task 7/8; `IncrementalUpdateWriter::append()`/`buildStreamObject()` (Task 6) hanno la stessa firma ovunque siano richiamati (Task 7/8); `XrefLink`/`XrefInfo::$objectOffsets` (Task 2-4) hanno lo stesso tipo `array<int,int>` in ogni punto.

---

**Plan complete and saved to `docs/superpowers/plans/2026-07-14-signature-tag-injector.md`. Two execution options:**

**1. Subagent-Driven (recommended)** - I dispatch a fresh subagent per task, review between tasks, fast iteration

**2. Inline Execution** - Execute tasks in this session using executing-plans, batch execution with checkpoints

**Which approach?**
