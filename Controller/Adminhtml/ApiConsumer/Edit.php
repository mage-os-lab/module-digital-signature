<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Controller\Adminhtml\ApiConsumer;

use MageOS\DigitalSignature\Api\ApiConsumerRepositoryInterface;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Result\PageFactory;

class Edit extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_DigitalSignature::api_consumer';

    public function __construct(
        Action\Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly ApiConsumerRepositoryInterface $apiConsumerRepository
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $consumerId = (int)$this->getRequest()->getParam('consumer_id');
        if ($consumerId) {
            try {
                $consumer = $this->apiConsumerRepository->getById($consumerId);
                $title = __('Edit integration "%1"', $consumer->getName());
            } catch (NoSuchEntityException) {
                $this->messageManager->addErrorMessage(__('This integration no longer exists.'));
                $resultRedirect = $this->resultRedirectFactory->create();

                return $resultRedirect->setPath('*/*/');
            }
        } else {
            $title = __('New API integration');
        }

        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('MageOS_DigitalSignature::api_consumer');
        $resultPage->getConfig()->getTitle()->prepend($title);

        return $resultPage;
    }
}
