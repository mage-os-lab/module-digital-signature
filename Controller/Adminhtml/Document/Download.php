<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Controller\Adminhtml\Document;

use MageOS\DigitalSignature\Api\Data\DocumentInterface;
use MageOS\DigitalSignature\Api\DocumentRepositoryInterface;
use MageOS\DigitalSignature\Api\DocumentStorageInterface;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultInterface;
use Psr\Log\LoggerInterface;

/**
 * Authorized download (ACL ::document) of the generated or signed PDF of a
 * document. Storage is in var/digitalsignature/ (not served by the web), the file
 * is read and returned in streaming as an attachment (analysis §12).
 */
class Download extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_DigitalSignature::document';

    public function __construct(
        Action\Context $context,
        private readonly DocumentRepositoryInterface $documentRepository,
        private readonly DocumentStorageInterface $storage,
        private readonly RawFactory $rawFactory,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $documentId = (int)$this->getRequest()->getParam('id');
        $type = (string)$this->getRequest()->getParam('type', 'signed');

        try {
            $document = $this->documentRepository->getById($documentId);
            $relativePath = $type === 'generated'
                ? $document->getPdfPath()
                : ($document->getSignedPdfPath() ?: $document->getPdfPath());

            if (!$relativePath || !$this->storage->exists($relativePath)) {
                throw new \RuntimeException((string)__('PDF not available for this document.'));
            }

            $content = $this->storage->read($relativePath);

            /** @var Raw $result */
            $result = $this->rawFactory->create();
            $result->setHeader('Content-Type', 'application/pdf', true);
            $result->setHeader('X-Content-Type-Options', 'nosniff', true);
            $result->setHeader(
                'Content-Disposition',
                'attachment; filename="' . $this->buildFileName($document, $type) . '"',
                true
            );
            $result->setHeader('Content-Length', (string)strlen($content), true);
            $result->setContents($content);

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('DigitalSignature: document download failed: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(
                __('Unable to download the PDF: %1', $e->getMessage())
            );
            /** @var Redirect $redirect */
            $redirect = $this->resultRedirectFactory->create();

            return $redirect->setPath('digitalsignature/document/index');
        }
    }

    private function buildFileName(DocumentInterface $document, string $type): string
    {
        $suffix = $type === 'generated' ? 'documento' : 'firmato';

        return sprintf('digitalsignature-%d-%s.pdf', (int)$document->getDocumentId(), $suffix);
    }
}
