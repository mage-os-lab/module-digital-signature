<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\File\Name;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\UrlInterface;
use Magento\MediaStorage\Helper\File\Storage\Database;
use Magento\MediaStorage\Model\File\UploaderFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * PDF upload for signature templates. Logic taken from Magento\Catalog\Model\ImageUploader, but as
 * the module's own class: Magento\MediaGalleryCatalogIntegration registers a global plugin
 * on ImageUploader that tries to import as an image asset (thumbnail) any file
 * moved by moveFileFromTmp, and on a PDF this fails, interrupting the template save.
 * Being a distinct class, no core plugin intercepts it.
 */
class PdfUploader
{
    private readonly WriteInterface $mediaDirectory;

    public function __construct(
        private readonly Database $coreFileStorageDatabase,
        Filesystem $filesystem,
        private readonly UploaderFactory $uploaderFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
        private readonly Name $fileNameLookup,
        private string $baseTmpPath,
        private string $basePath,
        private array $allowedExtensions,
        private array $allowedMimeTypes = []
    ) {
        $this->mediaDirectory = $filesystem->getDirectoryWrite(DirectoryList::MEDIA);
    }

    private function getFilePath(string $path, string $fileName): string
    {
        return rtrim($path, '/') . '/' . ltrim($fileName, '/');
    }

    /**
     * @throws LocalizedException
     */
    public function moveFileFromTmp(string $fileName, bool $returnRelativePath = false): string
    {
        $baseImagePath = $this->getFilePath(
            $this->basePath,
            $this->fileNameLookup->getNewFileName(
                $this->mediaDirectory->getAbsolutePath($this->getFilePath($this->basePath, $fileName))
            )
        );
        $baseTmpImagePath = $this->getFilePath($this->baseTmpPath, $fileName);

        try {
            $this->coreFileStorageDatabase->renameFile($baseTmpImagePath, $baseImagePath);
            $this->mediaDirectory->renameFile($baseTmpImagePath, $baseImagePath);
        } catch (\Exception $e) {
            $this->logger->critical($e);
            throw new LocalizedException(__('Something went wrong while saving the file(s).'), $e);
        }

        return $returnRelativePath ? $baseImagePath : $fileName;
    }

    /**
     * @return array<string, mixed>
     * @throws LocalizedException
     */
    public function saveFileToTmpDir(string $fileId): array
    {
        /** @var \Magento\MediaStorage\Model\File\Uploader $uploader */
        $uploader = $this->uploaderFactory->create(['fileId' => $fileId]);
        $uploader->setAllowedExtensions($this->allowedExtensions);
        $uploader->setAllowRenameFiles(true);
        if (!$uploader->checkMimeType($this->allowedMimeTypes)) {
            throw new LocalizedException(__('File validation failed.'));
        }
        $result = $uploader->save($this->mediaDirectory->getAbsolutePath($this->baseTmpPath));

        if (!$result) {
            throw new LocalizedException(__('File can not be saved to the destination folder.'));
        }
        unset($result['path']);

        $result['tmp_name'] = isset($result['tmp_name']) ? str_replace('\\', '/', $result['tmp_name']) : '';
        $result['url'] = $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA)
            . $this->getFilePath($this->baseTmpPath, $result['file']);
        $result['name'] = $result['file'];

        if (isset($result['file'])) {
            try {
                $relativePath = $this->getFilePath($this->baseTmpPath, $result['file']);
                $this->coreFileStorageDatabase->saveFile($relativePath);
            } catch (\Exception $e) {
                $this->logger->critical($e);
                throw new LocalizedException(__('Something went wrong while saving the file(s).'), $e);
            }
        }

        return $result;
    }
}
