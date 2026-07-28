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
 * Orchestrator of the document lifecycle: PDF generation from the template,
 * starting the signature at the provider, status update from polling.
 * Executed ONLY by the queue consumers (never in a web request).
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
     * Takes a pending document all the way to the "sent" status (generates + sends).
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
     * Generates the PDF from the template replacing the tags with the signer's data.
     *
     * @throws LocalizedException
     */
    public function generate(DocumentInterface $document): void
    {
        $templateId = $document->getTemplateId();
        if ($templateId === null) {
            throw new LocalizedException(__('The document has no associated template.'));
        }
        $email = (string)($document->getSignerEmail() ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new LocalizedException(__('Signer email missing or invalid.'));
        }

        $fileRow = $this->templateResource->getPdfPathForStore($templateId, (int)($document->getStoreId() ?? 0));
        if ($fileRow === null) {
            throw new LocalizedException(__('Template %1 has no uploaded PDF file.', $templateId));
        }
        [, $templatePdfPath] = $fileRow;
        $mediaDir = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
        if (!$mediaDir->isExist($templatePdfPath)) {
            throw new LocalizedException(__('Template PDF file not found: %1', $templatePdfPath));
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
     * Sends the generated PDF to the provider and starts the signature process.
     *
     * @throws LocalizedException|ProviderException
     */
    public function send(DocumentInterface $document): void
    {
        $pdfPath = $document->getPdfPath();
        if ($pdfPath === null) {
            throw new LocalizedException(__('The document has no generated PDF to send.'));
        }
        $provider = $this->providerPool->get($document->getProviderCode());

        // Plaintext token only transient: the hash goes into the DB
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

        // Document ready for signature: notification to the customer (if enabled)
        $this->notifier->notifyDocumentReady($document);
    }

    /**
     * Queries the provider and realigns the internal status (callback/polling).
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
            // Unknown status: log it and wait for the mapping in the config
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
            // No useful transition: the document remains pending
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

        // Notifications tied to the final outcome of the signature process
        if ($mapped === Status::SIGNED) {
            $this->notifier->notifyDocumentSigned($document);
        } elseif ($mapped === Status::DECLINED || $mapped === Status::EXPIRED) {
            $this->notifier->notifyDocumentOutcome($document);
        }
    }
}
