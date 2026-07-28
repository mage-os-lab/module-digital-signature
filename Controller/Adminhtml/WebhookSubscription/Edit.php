<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Controller\Adminhtml\WebhookSubscription;

use MageOS\DigitalSignature\Api\WebhookSubscriptionRepositoryInterface;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

class Edit extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_DigitalSignature::webhook_subscription';

    public function __construct(
        Action\Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly WebhookSubscriptionRepositoryInterface $webhookSubscriptionRepository
    ) {
        parent::__construct($context);
    }

    public function execute(): Page|Redirect
    {
        $id = (int)$this->getRequest()->getParam('subscription_id');
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('MageOS_DigitalSignature::webhook_subscription');

        if ($id) {
            try {
                $subscription = $this->webhookSubscriptionRepository->getById($id);
                $title = __('Edit Webhook Subscription "%1"', $subscription->getTargetUrl());
            } catch (NoSuchEntityException) {
                $this->messageManager->addErrorMessage(__('This subscription no longer exists.'));
                /** @var Redirect $resultRedirect */
                $resultRedirect = $this->resultRedirectFactory->create();

                return $resultRedirect->setPath('*/*/');
            }
        } else {
            $title = __('New Webhook Subscription');
        }

        $resultPage->getConfig()->getTitle()->prepend($title);

        return $resultPage;
    }
}
