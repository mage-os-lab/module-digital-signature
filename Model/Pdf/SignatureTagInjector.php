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

    private const NEW_FONT_RESOURCE_NAME = 'MDSHelv1';
    private const FONT_SIZE_PT = 10;

    public function __construct(
        private readonly PageTreeResolver $pageResolver,
        private readonly IncrementalUpdateWriter $updateWriter
    ) {
    }

    /**
     * Inserisce il tag come un content stream AGGIUNTIVO (non modifica il
     * content stream esistente): la pagina viene riscritta con /Contents
     * come array [content-originale, nuovo-content-tag], come previsto dallo
     * standard PDF (i content stream di un array vengono concatenati dal
     * visualizzatore). Evita di dover leggere/decomprimere il content stream
     * originale, che può usare filtri o convenzioni non note.
     *
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
        $fontName = self::NEW_FONT_RESOURCE_NAME;

        $tagStreamContent = sprintf(
            'q BT /%s %d Tf %s %s Td (%s) Tj ET Q',
            $fontName,
            self::FONT_SIZE_PT,
            $this->formatNumber($xPoints),
            $this->formatNumber($yPoints),
            $escapedTag
        );

        // Primo numero oggetto mai usato nella revisione corrente: garantito
        // libero, non richiede di scandire l'intero file per trovarne uno.
        $newContentNumber = $page->xrefSize;
        $newContentObject = $this->updateWriter->buildStreamObject($newContentNumber, $tagStreamContent, false);

        $newPageDict = $this->addContentToPage($page->pageDictText, $page->contentObjectNumber, $newContentNumber);

        // $newContentNumber ha già reclamato $page->xrefSize in questa stessa
        // incremental update: il font, per non collidere, prende il numero
        // successivo, anch'esso mai usato in nessuna revisione precedente.
        $fontObjectNumber = $page->xrefSize + 1;

        $modifiedObjects = [
            [
                'number' => $fontObjectNumber,
                'body' => sprintf(
                    "%d 0 obj\n<</Type /Font /Subtype /Type1 /BaseFont /Helvetica>>\nendobj\n",
                    $fontObjectNumber
                ),
            ]
        ];

        if ($page->resourcesObjectNumber !== null) {
            // /Resources è un oggetto separato (riferimento indiretto): il
            // font va scritto lì, il dizionario pagina resta invariato oltre
            // al patch di /Contents.
            $newResourcesContent = $this->addFontToResourcesContent($page->resourcesDictText, $fontObjectNumber);
            $modifiedObjects[] = [
                'number' => $page->resourcesObjectNumber,
                'body' => sprintf(
                    "%d 0 obj\n<<%s>>\nendobj\n",
                    $page->resourcesObjectNumber,
                    $newResourcesContent
                ),
            ];
        } else {
            $newPageDict = $this->addFontResource($newPageDict, $fontObjectNumber);
        }

        $newPageObject = sprintf("%d 0 obj\n<<%s>>\nendobj\n", $page->pageObjectNumber, $newPageDict);

        $modifiedObjects[] = ['number' => $page->pageObjectNumber, 'body' => $newPageObject];
        $modifiedObjects[] = ['number' => $newContentNumber, 'body' => $newContentObject];

        return $this->updateWriter->append($pdf, $originalPdf, $modifiedObjects);
    }

    /**
     * Aggiunge "/Font <</MDSHelv1 N 0 R>>" al dizionario /Resources già
     * presente nel testo del dizionario pagina (garantito da PageTreeResolver),
     * bilanciando correttamente eventuali sotto-dizionari annidati.
     *
     * @throws LocalizedException
     */
    private function addFontResource(string $pageDict, int $fontObjectNumber): string
    {
        if (!preg_match('/\/Resources\s*<</', $pageDict, $resMatch, PREG_OFFSET_CAPTURE)) {
            // Difensivo: PageTreeResolver ha già garantito la presenza di /Resources inline.
            throw new LocalizedException(__('PDF non supportato: /Resources non ritrovato per l\'aggiornamento.'));
        }
        $resourcesContentStart = $resMatch[0][1] + strlen($resMatch[0][0]);
        [$resourcesContent, $resourcesContentEnd] = DictFields::extractBalancedDict($pageDict, $resourcesContentStart);

        $newResourcesContent = $this->addFontToResourcesContent($resourcesContent, $fontObjectNumber);

        return substr($pageDict, 0, $resourcesContentStart)
            . $newResourcesContent
            . substr($pageDict, $resourcesContentEnd);
    }

    /**
     * Inserisce il riferimento al nuovo font nel contenuto testuale (già
     * isolato, senza delimitatori << >>) di un dizionario /Resources,
     * indipendentemente dal fatto che provenga dal dizionario pagina inline
     * o da un oggetto /Resources separato.
     */
    private function addFontToResourcesContent(string $resourcesContent, int $fontObjectNumber): string
    {
        if (preg_match('/\/Font\s*<</', $resourcesContent, $fontMatch, PREG_OFFSET_CAPTURE)) {
            // Se esiste già il dizionario /Font, inseriamo il nostro font al suo interno
            $fontContentStart = $fontMatch[0][1] + strlen($fontMatch[0][0]);

            return substr($resourcesContent, 0, $fontContentStart)
                . sprintf('/%s %d 0 R ', self::NEW_FONT_RESOURCE_NAME, $fontObjectNumber)
                . substr($resourcesContent, $fontContentStart);
        }

        // Altrimenti aggiungiamo il dizionario /Font completo
        return $resourcesContent
            . sprintf(' /Font <</%s %d 0 R>>', self::NEW_FONT_RESOURCE_NAME, $fontObjectNumber);
    }

    /**
     * Sostituisce "/Contents N 0 R" con "/Contents [N 0 R M 0 R]" nel testo
     * del dizionario pagina, dove M è il numero del nuovo content stream.
     *
     * @throws LocalizedException
     */
    private function addContentToPage(string $pageDict, int $existingContentNumber, int $newContentNumber): string
    {
        $pattern = '/\/Contents\s+' . $existingContentNumber . '\s+0\s+R/';
        $replacement = sprintf('/Contents [%d 0 R %d 0 R]', $existingContentNumber, $newContentNumber);
        $newDict = preg_replace($pattern, $replacement, $pageDict, 1, $count);
        if ($count !== 1) {
            throw new LocalizedException(
                __('PDF non supportato: /Contents della pagina non riconosciuto per l\'inserimento del tag.')
            );
        }

        return $newDict;
    }

    private function formatNumber(float $value): string
    {
        $formatted = rtrim(rtrim(sprintf('%.2F', round($value, 2)), '0'), '.');

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }
}
