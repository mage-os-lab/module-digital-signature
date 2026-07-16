<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Controller\Adminhtml\Template;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;

class PreviewTmp extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_DigitalSignature::template';
    private const BASE_TMP_PATH = 'digitalsignature/tmp';

    public function __construct(
        Action\Context $context,
        private readonly RawFactory $resultRawFactory,
        private readonly Filesystem $filesystem
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $fileName = basename((string)$this->getRequest()->getParam('file', ''));
        if ($fileName === '') {
            throw new LocalizedException(__('File non specificato.'));
        }

        $mediaDirectory = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
        $relativePath = self::BASE_TMP_PATH . '/' . $fileName;

        if (!$mediaDirectory->isFile($relativePath)) {
            throw new LocalizedException(__('Il file temporaneo richiesto non esiste.'));
        }

        $content = $mediaDirectory->readFile($relativePath);

        $resultRaw = $this->resultRawFactory->create();
        $resultRaw->setHeader('Content-Type', 'application/pdf');
        $resultRaw->setHeader('Content-Length', (string)strlen($content));
        $resultRaw->setHeader('Content-Disposition', 'inline; filename="' . $fileName . '"');
        $resultRaw->setContents($content);

        return $resultRaw;
    }
}
