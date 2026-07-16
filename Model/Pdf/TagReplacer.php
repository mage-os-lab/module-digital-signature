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
    public const FIELD_REGEX = '/\{FIELD:([a-z0-9_]+)\}/';

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
        private readonly IncrementalUpdateWriter $updateWriter,
        private readonly \MageOS\DigitalSignature\Api\MergeFieldPoolInterface $mergeFieldPool,
        private readonly \MageOS\DigitalSignature\Model\Pdf\PreviewOrderContext $previewOrderContext,
        private readonly \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
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
     * Merge field tag trovati nel PDF.
     *
     * @return string[] codici campo, es. ["order_number", "grand_total"]
     */
    public function findFieldTags(string $pdf): array
    {
        $tags = [];
        foreach ($this->extractStreamObjects($pdf) as $object) {
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
     * Sostituisce l'email placeholder di tutti i tag con quella reale e i merge fields dinamici.
     *
     * Strategia ibrida: l'oggetto aggiornato viene appeso con una nuova sezione
     * xref (incremental update) e i byte dell'oggetto originale vengono
     * sbiancati in place con spazi della STESSA lunghezza — gli offset della
     * xref esistente restano validi e il tag placeholder non è più presente
     * nel file, nemmeno per scanner testuali non conformi allo standard.
     *
     * @throws LocalizedException se nessun tag è presente o il PDF non è supportato
     */
    public function replaceSignerEmail(string $pdf, string $email, ?\MageOS\DigitalSignature\Model\MergeField\Context $context = null): string
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
        // oggetti-tag già sbiancati.
        $originalPdf = $pdf;

        $modifiedObjects = [];
        foreach ($this->extractStreamObjects($pdf) as $object) {
            $hasWsign = (bool)preg_match(self::TAG_REGEX, $object['content']);
            $hasField = (bool)preg_match(self::FIELD_REGEX, $object['content']);

            if (!$hasWsign && !$hasField) {
                continue;
            }

            $newContent = $object['content'];
            if ($hasWsign) {
                $newContent = preg_replace_callback(self::TAG_REGEX, $replacer, $newContent);
                // Il colore configurato va applicato SOLO nella generazione reale del
                // documento per un ordine (context non nullo): l'anteprima/dry-run
                // (context nullo, vedi Preview.php e TemplateValidator) deve mostrare
                // il tag ben visibile, altrimenti il merchant non può verificarne la
                // posizione prima di confermare il template.
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
            // Sbianca i byte dell'oggetto originale preservandone la lunghezza
            $pdf = substr_replace($pdf, str_repeat(' ', $object['length']), $object['offset'], $object['length']);
        }
        if (!$modifiedObjects) {
            throw new \MageOS\DigitalSignature\Exception\NoSignatureTagException(
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
