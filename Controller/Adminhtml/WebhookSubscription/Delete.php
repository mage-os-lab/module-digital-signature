<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Controller\Adminhtml\WebhookSubscription;

use MageOS\DigitalSignature\Api\WebhookSubscriptionRepositoryInterface;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\NoSuchEntityException;

class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_DigitalSignature::webhook_subscription';

    public function __construct(
        Action\Context $context,
        private readonly WebhookSubscriptionRepositoryInterface $webhookSubscriptionRepository
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $id = (int)$this->getRequest()->getParam('subscription_id');

        if (!$id) {
            $this->messageManager->addErrorMessage(__('Unable to find the subscription to delete.'));

            return $resultRedirect->setPath('*/*/');
        }

        try {
            $subscription = $this->webhookSubscriptionRepository->getById($id);
            $this->webhookSubscriptionRepository->delete($subscription);
            $this->messageManager->addSuccessMessage(__('Webhook subscription deleted successfully.'));
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(__('Webhook subscription not found.'));
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Error while deleting the subscription: %1', $e->getMessage()));
        }

        return $resultRedirect->setPath('*/*/');
    }
}
