<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Controller\Adminhtml\DossierExport;

use MageOS\DigitalSignature\Api\Data\DossierExportInterface;
use MageOS\DigitalSignature\Api\DossierExportRepositoryInterface;
use MageOS\DigitalSignature\Model\Dossier\DossierFileLocator;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Redirect;
use Psr\Log\LoggerInterface;

/**
 * Streams a ready dossier ZIP. The path comes from the export row, never from
 * the request; the file stays on disk until it expires or is deleted.
 */
class Download extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_DigitalSignature::document_dossier_export';

    public function __construct(
        Action\Context $context,
        private readonly DossierExportRepositoryInterface $repository,
        private readonly DossierFileLocator $fileLocator,
        private readonly FileFactory $fileFactory,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): ResponseInterface|Redirect
    {
        try {
            $export = $this->repository->getById((int)$this->getRequest()->getParam('id'));
            $path = $export->getFilePath();
            if ($export->getStatus() !== DossierExportInterface::STATUS_READY
                || $path === null
                || !$this->fileLocator->exists($path)
            ) {
                $this->messageManager->addErrorMessage(__('This dossier export is not available for download.'));

                return $this->redirectToGrid();
            }

            return $this->fileFactory->create(
                'dossier-' . $export->getExportId() . '.zip',
                ['type' => 'filename', 'value' => $path, 'rm' => false],
                DirectoryList::VAR_DIR,
                'application/zip'
            );
        } catch (\Throwable $e) {
            $this->logger->error('DigitalSignature: dossier export download failed: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(__('This dossier export is not available for download.'));

            return $this->redirectToGrid();
        }
    }

    private function redirectToGrid(): Redirect
    {
        return $this->resultRedirectFactory->create()->setPath('digitalsignature/dossierexport/index');
    }
}
