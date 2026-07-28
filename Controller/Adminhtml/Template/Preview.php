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
 * Regenerates on-demand (no persistence) the template PDF with the signature
 * tag replaced by a fixed placeholder email, to give the merchant a
 * visual confirmation that the replacement happens at the correct position.
 * Same write path used in production and in the upload validation
 * dry-run (TemplateValidator::PREVIEW_SIGNER_EMAIL).
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
                throw new \RuntimeException((string)__('The template has no PDF file uploaded for this store view.'));
            }
            [, $templatePdfPath] = $fileRow;

            $mediaDir = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
            if (!$mediaDir->isExist($templatePdfPath)) {
                throw new \RuntimeException((string)__('Template PDF file not found.'));
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
                __('Unable to generate the preview: %1', $e->getMessage())
            );
            /** @var Redirect $redirect */
            $redirect = $this->resultRedirectFactory->create();

            return $redirect->setPath('*/*/edit', ['template_id' => $templateId, 'store' => $storeId]);
        }
    }
}
