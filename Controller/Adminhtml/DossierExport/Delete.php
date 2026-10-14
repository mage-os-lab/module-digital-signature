<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Controller\Adminhtml\DossierExport;

use MageOS\DigitalSignature\Api\DossierExportRepositoryInterface;
use MageOS\DigitalSignature\Model\Dossier\DossierExportDeleter;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Psr\Log\LoggerInterface;

class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_DigitalSignature::document_dossier_export';

    public function __construct(
        Action\Context $context,
        private readonly DossierExportRepositoryInterface $repository,
        private readonly DossierExportDeleter $deleter,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $exportId = (int)$this->getRequest()->getParam('id');
        try {
            $this->deleter->delete($this->repository->getById($exportId));
            $this->messageManager->addSuccessMessage(__('Dossier export #%1 deleted.', $exportId));
        } catch (\Throwable $e) {
            $this->logger->error('DigitalSignature: dossier export delete failed: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(__('Dossier export #%1 could not be deleted.', $exportId));
        }

        return $this->resultRedirectFactory->create()->setPath('digitalsignature/dossierexport/index');
    }
}
