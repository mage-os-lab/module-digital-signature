<?php

declare(strict_types=1);

namespace MageOS\DigitalSignature\Controller\Adminhtml\Document;

use MageOS\DigitalSignature\Model\Document\Export\ConvertToXlsx;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Response\Http\FileFactory;
use Psr\Log\LoggerInterface;

/**
 * Exports the Document grid (current filters/search applied) to XLSX. CSV
 * and XML use the core Magento export controllers declared directly in the
 * listing XML (mui/export/gridToCsv, mui/export/gridToXml); XLSX has no
 * core equivalent, hence this dedicated controller and ACL resource.
 *
 * Must implement HttpPostActionInterface (not Get): the grid's export button
 * (Magento_Ui/js/grid/export) always issues a POST request, so a Get-only
 * controller is rejected by HttpMethodValidator before execute() ever runs.
 */
class ExportXlsx extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_DigitalSignature::document_export';

    public function __construct(
        Action\Context $context,
        private readonly ConvertToXlsx $converter,
        private readonly FileFactory $fileFactory,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    /**
     * Restrict this action to the exact grid namespace it is wired for, in addition to the ACL check.
     *
     * The `namespace` request parameter is attacker-controlled and drives which UI component
     * {@see ConvertToXlsx} exports via {@see \Magento\Ui\Component\MassAction\Filter::getComponent()}.
     * Without pinning it here, a user granted only the `document_export` resource could pass
     * e.g. `?namespace=customer_listing` and export an unrelated, more sensitive grid.
     */
    protected function _isAllowed(): bool
    {
        if ($this->getRequest()->getParam('namespace') !== 'digitalsignature_document_listing') {
            return false;
        }

        return parent::_isAllowed();
    }

    public function execute()
    {
        try {
            return $this->fileFactory->create(
                'document_export.xlsx',
                $this->converter->getXlsxFile(),
                'var'
            );
        } catch (\Throwable $e) {
            $this->logger->error('DigitalSignature: document XLSX export failed: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(
                __('An error occurred while generating the export file.')
            );
            $redirect = $this->resultRedirectFactory->create();

            return $redirect->setPath('digitalsignature/document/index');
        }
    }
}
