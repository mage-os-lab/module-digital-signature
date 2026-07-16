<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Service;

use MageOS\DigitalSignature\Api\Data\DocumentInterface;
use MageOS\DigitalSignature\Api\DocumentRepositoryInterface;
use MageOS\DigitalSignature\Api\DocumentStorageInterface;
use MageOS\DigitalSignature\Api\SignProviderPoolInterface;
use MageOS\DigitalSignature\Exception\ProviderException;
use MageOS\DigitalSignature\Model\Document\Status;
use MageOS\DigitalSignature\Model\Notification\Notifier;
use MageOS\DigitalSignature\Model\Pdf\TagReplacer;
use MageOS\DigitalSignature\Model\ResourceModel\Template as TemplateResource;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Math\Random;
use Psr\Log\LoggerInterface;

/**
 * Orchestratore del ciclo di vita del documento: generazione PDF dal template,
 * avvio firma presso il provider, aggiornamento stato da polling.
 * Eseguito SOLO dai consumer della coda (mai in richiesta web).
 */
class DocumentProcessor
{
    public function __construct(
        private readonly DocumentRepositoryInterface $documentRepository,
        private readonly TemplateResource $templateResource,
        private readonly TagReplacer $tagReplacer,
        private readonly DocumentStorageInterface $storage,
        private readonly SignProviderPoolInterface $providerPool,
        private readonly Filesystem $filesystem,
        private readonly Random $random,
        private readonly Notifier $notifier,
        private readonly LoggerInterface $logger,
        private readonly \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        private readonly \Magento\Sales\Model\ResourceModel\Order\Invoice\CollectionFactory $invoiceCollectionFactory
    ) {
    }

    /**
     * Porta un documento pending fino allo stato "sent" (genera + invia).
     *
     * @throws LocalizedException|ProviderException
     */
    public function process(DocumentInterface $document): void
    {
        if ($document->getStatus() === Status::PENDING) {
            $this->generate($document);
        }
        if ($document->getStatus() === Status::GENERATED) {
            $this->send($document);
        }
    }

    /**
     * Genera il PDF dal template sostituendo i tag con i dati del firmatario.
     *
     * @throws LocalizedException
     */
    public function generate(DocumentInterface $document): void
    {
        $templateId = $document->getTemplateId();
        if ($templateId === null) {
            throw new LocalizedException(__('Il documento non ha un template associato.'));
        }
        $email = (string)($document->getSignerEmail() ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new LocalizedException(__('Email del firmatario mancante o non valida.'));
        }

        $fileRow = $this->templateResource->getPdfPathForStore($templateId, (int)($document->getStoreId() ?? 0));
        if ($fileRow === null) {
            throw new LocalizedException(__('Il template %1 non ha un file PDF caricato.', $templateId));
        }
        [, $templatePdfPath] = $fileRow;
        $mediaDir = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
        if (!$mediaDir->isExist($templatePdfPath)) {
            throw new LocalizedException(__('File PDF del template non trovato: %1', $templatePdfPath));
        }

        $order = $this->orderRepository->get($document->getOrderId());
        $invoice = null;
        $triggerCode = $document->getTriggerCode();
        if ($triggerCode === 'invoice_created' || $triggerCode === 'invoice_paid') {
            $collection = $this->invoiceCollectionFactory->create()
                ->addFieldToFilter('order_id', $order->getEntityId())
                ->setOrder('entity_id', 'DESC')
                ->setPageSize(1);
            if ($collection->getSize() > 0) {
                $invoice = $collection->getFirstItem();
            }
        }

        $context = new \MageOS\DigitalSignature\Model\MergeField\Context(
            $order,
            $invoice,
            (int)$document->getStoreId()
        );

        $generatedPdf = $this->tagReplacer->replaceSignerEmail(
            $mediaDir->readFile($templatePdfPath),
            $email,
            $context
        );
        $relativePath = sprintf(
            'order_%d/document_%d.pdf',
            $document->getOrderId(),
            (int)$document->getDocumentId()
        );
        $this->storage->write($relativePath, $generatedPdf);

        $previousStatus = $document->getStatus();
        $document->setPdfPath($relativePath);
        $document->setStatus(Status::GENERATED);
        $this->documentRepository->save($document);
        $this->documentRepository->addLog(
            $document,
            'status_change',
            $previousStatus,
            Status::GENERATED,
            'PDF generato dal template'
        );
    }

    /**
     * Invia il PDF generato al provider e avvia il processo di firma.
     *
     * @throws LocalizedException|ProviderException
     */
    public function send(DocumentInterface $document): void
    {
        $pdfPath = $document->getPdfPath();
        if ($pdfPath === null) {
            throw new LocalizedException(__('Il documento non ha un PDF generato da inviare.'));
        }
        $provider = $this->providerPool->get($document->getProviderCode());

        // Token in chiaro solo transiente: in DB va l'hash
        $callbackToken = $this->random->getRandomString(40);
        $document->setCallbackTokenHash(hash('sha256', $callbackToken));
        $this->documentRepository->save($document);

        $result = $provider->start($document, $this->storage->read($pdfPath), $callbackToken);

        $previousStatus = $document->getStatus();
        $document->setProviderProcessId($result->getProcessId());
        $document->setStatus(Status::SENT);
        $document->setErrorMessage(null);
        $this->documentRepository->save($document);
        $this->documentRepository->addLog(
            $document,
            'status_change',
            $previousStatus,
            Status::SENT,
            'Processo di firma avviato presso ' . $document->getProviderCode(),
            $result->getRawResponse()
        );

        // Documento pronto per la firma: avviso al cliente (se abilitato)
        $this->notifier->notifyDocumentReady($document);
    }

    /**
     * Interroga il provider e riallinea lo stato interno (callback/polling).
     *
     * @throws ProviderException
     */
    public function refreshStatus(DocumentInterface $document): void
    {
        if (Status::isFinal($document->getStatus())) {
            return;
        }
        $provider = $this->providerPool->get($document->getProviderCode());
        $statusResult = $provider->fetchStatus($document);
        $rawStatus = $statusResult->getRawStatus();
        $mapped = $provider->mapStatus($rawStatus);

        if ($mapped === null) {
            // Stato sconosciuto: si logga e si attende la mappatura in config
            $this->logger->warning(
                sprintf(
                    'DigitalSignature: stato provider non mappato "%s" (documento %d, provider %s)',
                    $rawStatus,
                    (int)$document->getDocumentId(),
                    $document->getProviderCode()
                )
            );
            $this->documentRepository->addLog(
                $document,
                'callback',
                null,
                null,
                'Stato provider non mappato: ' . $rawStatus,
                $statusResult->getRawResponse()
            );

            return;
        }
        if ($mapped === $document->getStatus() || !Status::isFinal($mapped)) {
            // Nessuna transizione utile: il documento resta in attesa
            return;
        }

        $previousStatus = $document->getStatus();
        if ($mapped === Status::SIGNED) {
            $signedPdf = $provider->downloadSignedPdf($document);
            $signedPath = sprintf(
                'order_%d/document_%d_signed.pdf',
                $document->getOrderId(),
                (int)$document->getDocumentId()
            );
            $this->storage->write($signedPath, $signedPdf);
            $document->setSignedPdfPath($signedPath);
        }
        $document->setStatus($mapped);
        $this->documentRepository->save($document);
        $this->documentRepository->addLog(
            $document,
            'status_change',
            $previousStatus,
            $mapped,
            'Stato aggiornato dal provider (raw: ' . $rawStatus . ')',
            $statusResult->getRawResponse()
        );

        // Notifiche legate all'esito finale del processo di firma
        if ($mapped === Status::SIGNED) {
            $this->notifier->notifyDocumentSigned($document);
        } elseif ($mapped === Status::DECLINED || $mapped === Status::EXPIRED) {
            $this->notifier->notifyDocumentOutcome($document);
        }
    }
}
