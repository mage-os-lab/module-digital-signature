<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Controller\Order;

use MageOS\DigitalSignature\Api\Data\DocumentInterface;
use MageOS\DigitalSignature\Api\DocumentRepositoryInterface;
use MageOS\DigitalSignature\Api\DocumentStorageInterface;
use MageOS\DigitalSignature\Model\Document\Status;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Forward;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Download del PDF firmato dall'area cliente ("i miei ordini").
 *
 * Riservato ai clienti registrati (no guest, analisi §13-bis): richiede il
 * login, verifica che il documento appartenga a un ordine del cliente loggato
 * ed espone solo documenti effettivamente firmati. Lo storage è in
 * var/digitalsignature/ (non servito dal web), il PDF viene letto e ritornato in
 * streaming come allegato.
 */
class DownloadSigned extends Action implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        private readonly CustomerSession $customerSession,
        private readonly DocumentRepositoryInterface $documentRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly DocumentStorageInterface $storage,
        private readonly RawFactory $rawFactory,
        private readonly ForwardFactory $forwardFactory,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        if (!$this->customerSession->isLoggedIn()) {
            $this->customerSession->setBeforeAuthUrl($this->_url->getCurrentUrl());
            /** @var Redirect $redirect */
            $redirect = $this->resultRedirectFactory->create();

            return $redirect->setPath('customer/account/login');
        }

        $documentId = (int)$this->getRequest()->getParam('id');

        try {
            $document = $this->documentRepository->getById($documentId);

            if (!$this->isOwnedByCustomer($document) || !$this->isDownloadable($document)) {
                return $this->notFound();
            }

            $relativePath = (string)$document->getSignedPdfPath();
            if (!$this->storage->exists($relativePath)) {
                return $this->notFound();
            }

            $content = $this->storage->read($relativePath);

            /** @var Raw $result */
            $result = $this->rawFactory->create();
            $result->setHeader('Content-Type', 'application/pdf', true);
            $result->setHeader('X-Content-Type-Options', 'nosniff', true);
            $result->setHeader(
                'Content-Disposition',
                'attachment; filename="' . $this->buildFileName($document) . '"',
                true
            );
            $result->setHeader('Content-Length', (string)strlen($content), true);
            $result->setContents($content);

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('DigitalSignature: download cliente fallito: ' . $e->getMessage());

            return $this->notFound();
        }
    }

    private function isOwnedByCustomer(DocumentInterface $document): bool
    {
        $customerId = (int)$this->customerSession->getCustomerId();
        if ($customerId <= 0) {
            return false;
        }

        try {
            $order = $this->orderRepository->get($document->getOrderId());
        } catch (\Exception $e) {
            return false;
        }

        return (int)$order->getCustomerId() === $customerId;
    }

    private function isDownloadable(DocumentInterface $document): bool
    {
        return $document->getStatus() === Status::SIGNED
            && (string)$document->getSignedPdfPath() !== '';
    }

    private function notFound(): Forward
    {
        /** @var Forward $forward */
        $forward = $this->forwardFactory->create();

        return $forward->forward('noroute');
    }

    private function buildFileName(DocumentInterface $document): string
    {
        return sprintf('contratto-firmato-%d.pdf', (int)$document->getDocumentId());
    }
}
