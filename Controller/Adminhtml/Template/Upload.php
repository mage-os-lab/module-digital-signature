<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Controller\Adminhtml\Template;

use MageOS\DigitalSignature\Model\Pdf\TemplateValidator;
use MageOS\DigitalSignature\Model\PdfUploader;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Driver\File as FileDriver;

class Upload extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_DigitalSignature::template';

    /** Must match the "baseTmpPath" argument of the PdfUploader virtualType in di.xml */
    private const BASE_TMP_PATH = 'digitalsignature/tmp';

    public function __construct(
        Action\Context $context,
        private readonly PdfUploader $pdfUploader,
        private readonly TemplateValidator $templateValidator,
        private readonly FileDriver $fileDriver,
        private readonly Filesystem $filesystem
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $fileId = $this->getRequest()->getParam('param_name', 'pdf_file');
        try {
            $result = $this->pdfUploader->saveFileToTmpDir($fileId);
            try {
                $this->validateUploadedPdf($result);
            } catch (\MageOS\DigitalSignature\Exception\NoSignatureTagException $e) {
                $result['warning'] = 'no_tag';
                $result['message'] = $e->getMessage();
            }
            // Key "file" = marker of a new upload to be moved on save
            $result['file'] = $result['name'];
            $result['cookie'] = [
                'name' => $this->_getSession()->getName(),
                'value' => $this->_getSession()->getSessionId(),
                'lifetime' => $this->_getSession()->getCookieLifetime(),
                'path' => $this->_getSession()->getCookiePath(),
                'domain' => $this->_getSession()->getCookieDomain(),
            ];
        } catch (\Exception $e) {
            $result = ['error' => $e->getMessage(), 'errorcode' => $e->getCode()];
        }

        /** @var Json $resultJson */
        $resultJson = $this->resultFactory->create(ResultFactory::TYPE_JSON);

        return $resultJson->setData($result);
    }

    /**
     * Upstream validation (magic bytes, classic xref, presence of signature tag):
     * if it fails, the temporary file is removed and the upload rejected.
     *
     * @param array<string, mixed> $uploadResult
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    private function validateUploadedPdf(array $uploadResult): void
    {
        // saveFileToTmpDir() removes the "path" key from the result (Magento core):
        // the absolute path must be rebuilt from the same baseTmpPath used by the uploader.
        $fileName = basename((string)($uploadResult['file'] ?? ''));
        $mediaDirectory = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
        $absolutePath = $mediaDirectory->getAbsolutePath(self::BASE_TMP_PATH . '/' . $fileName);
        $content = $this->fileDriver->fileGetContents($absolutePath);
        try {
            $this->templateValidator->validate($content);
        } catch (\MageOS\DigitalSignature\Exception\NoSignatureTagException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->fileDriver->deleteFile($absolutePath);
            throw $e;
        }
    }
}
