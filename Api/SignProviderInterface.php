<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Api;

use MageOS\DigitalSignature\Api\Data\DocumentInterface;
use MageOS\DigitalSignature\Exception\ProviderException;
use MageOS\DigitalSignature\Model\Provider\Result\StartResult;
use MageOS\DigitalSignature\Model\Provider\Result\StatusResult;

/**
 * Contratto dei connettori di firma digitale (pattern pluggable tipo payment method).
 *
 * I connettori NON modificano il documento e NON accedono allo storage: ricevono
 * i contenuti come stringhe binarie e restituiscono risultati; la persistenza è
 * responsabilità del servizio chiamante.
 */
interface SignProviderInterface
{
    public function getCode(): string;

    public function getLabel(): string;

    public function isEnabled(?int $storeId = null): bool;

    /**
     * Carica il PDF presso il provider e avvia il processo di firma.
     *
     * @param string $pdfContent contenuto binario del PDF generato
     * @param string $callbackToken token in chiaro da inserire nell'URL di callback
     * @throws ProviderException
     */
    public function start(DocumentInterface $document, string $pdfContent, string $callbackToken): StartResult;

    /**
     * Interroga lo stato del processo presso il provider (polling).
     *
     * @throws ProviderException
     */
    public function fetchStatus(DocumentInterface $document): StatusResult;

    /**
     * Scarica il PDF firmato (contenuto binario).
     *
     * @throws ProviderException
     */
    public function downloadSignedPdf(DocumentInterface $document): string;

    /**
     * Annulla/elimina il processo presso il provider (per rigenerazione/reinvio).
     * Non deve fallire se il processo non esiste più.
     *
     * @throws ProviderException
     */
    public function cancel(DocumentInterface $document): void;

    /**
     * Mappa lo stato grezzo del provider su uno stato interno
     * (Model\Document\Status::*). Null = stato sconosciuto (da loggare).
     */
    public function mapStatus(string $providerStatus): ?string;
}
