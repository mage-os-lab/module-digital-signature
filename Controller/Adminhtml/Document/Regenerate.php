<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Controller\Adminhtml\Document;

use MageOS\DigitalSignature\Model\Service\DocumentManager;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Psr\Log\LoggerInterface;

/**
 * Rigenera/reinvia un documento firma attivo dalla vista ordine in admin.
 */
class Regenerate extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_DigitalSignature::document';

    public function __construct(
        Action\Context $context,
        private readonly DocumentManager $documentManager,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $documentId = (int)$this->getRequest()->getParam('id');
        $orderId = (int)$this->getRequest()->getParam('order_id');
        $resultRedirect = $this->resultRedirectFactory->create();

        try {
            $newId = $this->documentManager->regenerate($documentId);
            $this->messageManager->addSuccessMessage(
                __('Documento rigenerato: nuovo documento #%1 in elaborazione.', $newId)
            );
        } catch (\Exception $e) {
            $this->logger->error('DigitalSignature: rigenerazione fallita: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(
                __('Impossibile rigenerare il documento: %1', $e->getMessage())
            );
        }

        return $resultRedirect->setPath('sales/order/view', ['order_id' => $orderId]);
    }
}
