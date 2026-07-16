<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Controller\Adminhtml\Document;

use MageOS\DigitalSignature\Model\Service\DocumentManager;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Genera manualmente i documenti firma (trigger MANUAL) per un ordine,
 * dalla vista ordine in admin.
 */
class Generate extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_DigitalSignature::document';

    public function __construct(
        Action\Context $context,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly DocumentManager $documentManager,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $orderId = (int)$this->getRequest()->getParam('order_id');
        $resultRedirect = $this->resultRedirectFactory->create();

        try {
            $order = $this->orderRepository->get($orderId);
            $this->documentManager->generateManual($order);
            $this->messageManager->addSuccessMessage(
                __('Richiesta di generazione documenti firma presa in carico.')
            );
        } catch (\Exception $e) {
            $this->logger->error('DigitalSignature: generazione manuale fallita: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(
                __('Impossibile generare i documenti firma: %1', $e->getMessage())
            );
        }

        return $resultRedirect->setPath('sales/order/view', ['order_id' => $orderId]);
    }
}
