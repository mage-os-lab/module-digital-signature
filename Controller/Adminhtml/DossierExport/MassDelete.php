<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Controller\Adminhtml\DossierExport;

use MageOS\DigitalSignature\Model\Dossier\DossierExportDeleter;
use MageOS\DigitalSignature\Model\ResourceModel\DossierExport\CollectionFactory;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Ui\Component\MassAction\Filter;
use Psr\Log\LoggerInterface;

class MassDelete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_DigitalSignature::document_dossier_export';

    public function __construct(
        Action\Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
        private readonly DossierExportDeleter $deleter,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    protected function _isAllowed(): bool
    {
        if ($this->getRequest()->getParam('namespace') !== 'digitalsignature_dossierexport_listing') {
            return false;
        }

        return parent::_isAllowed();
    }

    public function execute(): Redirect
    {
        $deleted = 0;
        $failed = 0;
        try {
            foreach ($this->filter->getCollection($this->collectionFactory->create()) as $export) {
                try {
                    $this->deleter->delete($export);
                    $deleted++;
                } catch (\Throwable $e) {
                    $failed++;
                    $this->logger->error('DigitalSignature: dossier export delete failed: ' . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('DigitalSignature: dossier export mass delete failed: ' . $e->getMessage());
            $failed++;
        }

        if ($deleted) {
            $this->messageManager->addSuccessMessage(__('%1 dossier export(s) deleted.', $deleted));
        }
        if ($failed) {
            $this->messageManager->addErrorMessage(__('%1 dossier export(s) could not be deleted.', $failed));
        }

        return $this->resultRedirectFactory->create()->setPath('digitalsignature/dossierexport/index');
    }
}
