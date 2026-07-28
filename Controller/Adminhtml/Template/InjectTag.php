<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Controller\Adminhtml\Template;

use MageOS\DigitalSignature\Model\Pdf\SignatureTagInjector;
use MageOS\DigitalSignature\Model\Pdf\TemplateValidator;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

class InjectTag extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_DigitalSignature::template';
    private const BASE_TMP_PATH = 'digitalsignature/tmp';

    public function __construct(
        Action\Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly Filesystem $filesystem,
        private readonly SignatureTagInjector $signatureTagInjector,
        private readonly TemplateValidator $templateValidator,
        private readonly StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultJson = $this->resultJsonFactory->create();

        try {
            $fileName = basename((string)$this->getRequest()->getParam('file', ''));
            if ($fileName === '') {
                throw new LocalizedException(__('File not specified.'));
            }

            $page = (int)$this->getRequest()->getParam('page', 0);
            $x = (float)$this->getRequest()->getParam('x', 0);
            $y = (float)$this->getRequest()->getParam('y', 0);
            $w = (float)$this->getRequest()->getParam('w', 0);
            $h = (float)$this->getRequest()->getParam('h', 0);

            if ($page <= 0 || $w <= 0 || $h <= 0) {
                throw new LocalizedException(__('Invalid signature parameters.'));
            }

            $mediaDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
            $relativePath = self::BASE_TMP_PATH . '/' . $fileName;

            if (!$mediaDirectory->isFile($relativePath)) {
                throw new LocalizedException(__('The temporary file does not exist.'));
            }

            $pdfContent = $mediaDirectory->readFile($relativePath);

            $modifiedPdf = $this->signatureTagInjector->injectAt(
                $pdfContent,
                $page,
                $x,
                $y,
                $w,
                $h,
                TemplateValidator::PREVIEW_SIGNER_EMAIL
            );

            $this->templateValidator->validate($modifiedPdf);

            $mediaDirectory->writeFile($relativePath, $modifiedPdf);

            $mediaUrl = $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA)
                . self::BASE_TMP_PATH . '/' . $fileName;

            return $resultJson->setData([
                'name' => $fileName,
                'file' => $fileName,
                'size' => strlen($modifiedPdf),
                'type' => 'application/pdf',
                'url' => $mediaUrl,
                'cookie' => [
                    'name' => $this->_getSession()->getName(),
                    'value' => $this->_getSession()->getSessionId(),
                    'lifetime' => $this->_getSession()->getCookieLifetime(),
                    'path' => $this->_getSession()->getCookiePath(),
                    'domain' => $this->_getSession()->getCookieDomain(),
                ]
            ]);

        } catch (\Exception $e) {
            return $resultJson->setData([
                'error' => $e->getMessage(),
                'errorcode' => $e->getCode()
            ]);
        }
    }
}
