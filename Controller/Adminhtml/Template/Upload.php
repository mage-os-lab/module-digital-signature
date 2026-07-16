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

    /** Deve combaciare con l'argomento "baseTmpPath" del virtualType PdfUploader in di.xml */
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
            // Chiave "file" = marcatore di upload nuovo da spostare al salvataggio
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
     * Validazione a monte (magic bytes, xref classico, presenza tag firma):
     * se fallisce, il file temporaneo viene rimosso e l'upload rifiutato.
     *
     * @param array<string, mixed> $uploadResult
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    private function validateUploadedPdf(array $uploadResult): void
    {
        // saveFileToTmpDir() rimuove la chiave "path" dal risultato (core Magento):
        // il path assoluto va ricostruito dalla stessa baseTmpPath usata dall'uploader.
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
