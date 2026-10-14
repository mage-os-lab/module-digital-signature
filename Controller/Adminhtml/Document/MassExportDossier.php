<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Controller\Adminhtml\Document;

use MageOS\DigitalSignature\Model\Dossier\DossierExportConfig;
use MageOS\DigitalSignature\Model\Dossier\DossierExportScheduler;
use MageOS\DigitalSignature\Model\Dossier\DossierFileLocator;
use MageOS\DigitalSignature\Model\ResourceModel\Document\CollectionFactory;
use MageOS\DigitalSignature\Model\Service\DossierBuilder;
use Magento\Backend\App\Action;
use Magento\Backend\Model\Auth\Session as BackendAuthSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Ui\Component\MassAction\Filter;
use Psr\Log\LoggerInterface;

/**
 * Export dossier (ZIP) mass action on the Signature documents grid (PDF +
 * certificate + manifest.json per document). Selections above
 * dossier_export/max_documents are refused; up to sync_threshold the ZIP is
 * written to a temporary file under var/ and streamed; larger selections are
 * queued and listed under Digital Signature > Dossier exports.
 */
class MassExportDossier extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_DigitalSignature::document_dossier_export';

    public function __construct(
        Action\Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
        private readonly DossierBuilder $dossierBuilder,
        private readonly DossierExportConfig $config,
        private readonly DossierExportScheduler $scheduler,
        private readonly DossierFileLocator $fileLocator,
        private readonly FileFactory $fileFactory,
        private readonly LoggerInterface $logger,
        private readonly BackendAuthSession $authSession
    ) {
        parent::__construct($context);
    }

    protected function _isAllowed(): bool
    {
        if ($this->getRequest()->getParam('namespace') !== 'digitalsignature_document_listing') {
            return false;
        }

        return parent::_isAllowed();
    }

    public function execute(): ResponseInterface|Redirect
    {
        try {
            $collection = $this->filter->getCollection($this->collectionFactory->create());
            $documentIds = array_map('intval', $collection->getAllIds());

            if (!$documentIds) {
                $this->messageManager->addErrorMessage(__('No documents were selected.'));

                return $this->redirectToGrid();
            }

            $count = count($documentIds);
            $mode = $this->config->resolveMode($count);
            $adminUser = $this->authSession->getUser();
            $adminUsername = $adminUser?->getUsername();

            if ($mode === DossierExportConfig::MODE_REJECT) {
                $this->messageManager->addErrorMessage(__(
                    'You selected %1 documents: the dossier export is limited to %2. Narrow the selection.',
                    $count,
                    $this->config->getMaxDocuments()
                ));

                return $this->redirectToGrid();
            }

            if ($mode === DossierExportConfig::MODE_ASYNC) {
                $exportId = $this->scheduler->schedule(
                    $documentIds,
                    $adminUser?->getId() !== null ? (int)$adminUser->getId() : null,
                    $adminUsername
                );
                $this->messageManager->addSuccessMessage(__(
                    'Dossier export #%1 queued (%2 documents). You will be notified when it is ready under Digital Signature > Dossier exports.',
                    $exportId,
                    $count
                ));

                return $this->redirectToGrid();
            }

            $relativePath = $this->fileLocator->newRelativePath('tmp');
            $this->dossierBuilder->buildToFile(
                $documentIds,
                $this->fileLocator->absolutePath($relativePath),
                $adminUsername
            );

            return $this->fileFactory->create(
                'dossier-' . date('Y-m-d-His') . '.zip',
                ['type' => 'filename', 'value' => $relativePath, 'rm' => true],
                DirectoryList::VAR_DIR,
                'application/zip'
            );
        } catch (\Throwable $e) {
            $this->logger->error('DigitalSignature: dossier export failed: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(
                __('An error occurred while generating the dossier.')
            );

            return $this->redirectToGrid();
        }
    }

    private function redirectToGrid(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create();
        $redirect->setPath('digitalsignature/document/index');

        return $redirect;
    }
}
