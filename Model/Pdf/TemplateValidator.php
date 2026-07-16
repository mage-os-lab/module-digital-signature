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
