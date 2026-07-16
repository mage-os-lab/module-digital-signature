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
 * Rigenera on-demand (nessuna persistenza) il PDF del template con il tag
 * firma sostituito da un'email segnaposto fissa, per dare al merchant una
 * conferma visiva che la sostituzione avvenga nella posizione corretta.
 * Stesso path di scrittura usato in produzione e nel dry-run di validazione
 * dell'upload (TemplateValidator::PREVIEW_SIGNER_EMAIL).
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
                throw new \RuntimeException((string)__('Il template non ha un file PDF caricato per questa vista.'));
            }
            [, $templatePdfPath] = $fileRow;

            $mediaDir = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
            if (!$mediaDir->isExist($templatePdfPath)) {
                throw new \RuntimeException((string)__('File PDF del template non trovato.'));
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
                __('Impossibile generare l\'anteprima: %1', $e->getMessage())
            );
            /** @var Redirect $redirect */
            $redirect = $this->resultRedirectFactory->create();

            return $redirect->setPath('*/*/edit', ['template_id' => $templateId, 'store' => $storeId]);
        }
    }
}
