<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Controller\Adminhtml\ApiConsumer;

use MageOS\DigitalSignature\Api\ApiConsumerRepositoryInterface;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;

class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_DigitalSignature::api_consumer';

    public function __construct(
        Action\Context $context,
        private readonly ApiConsumerRepositoryInterface $apiConsumerRepository
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $consumerId = (int)$this->getRequest()->getParam('consumer_id');
        if (!$consumerId) {
            $this->messageManager->addErrorMessage(__('No integration selected.'));

            return $resultRedirect->setPath('*/*/');
        }

        try {
            $consumer = $this->apiConsumerRepository->getById($consumerId);
            $this->apiConsumerRepository->delete($consumer);
            $this->messageManager->addSuccessMessage(__('Integration deleted.'));
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage($e->getMessage());

            return $resultRedirect->setPath('*/*/edit', ['consumer_id' => $consumerId]);
        }

        return $resultRedirect->setPath('*/*/');
    }
}
