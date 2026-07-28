<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Controller\Adminhtml\WebhookSubscription;

use MageOS\DigitalSignature\Api\WebhookSubscriptionRepositoryInterface;
use MageOS\DigitalSignature\Model\WebhookSubscriptionFactory;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_DigitalSignature::webhook_subscription';

    private const PERSIST_KEY = 'digitalsignature_webhooksubscription';

    public function __construct(
        Action\Context $context,
        private readonly WebhookSubscriptionRepositoryInterface $webhookSubscriptionRepository,
        private readonly WebhookSubscriptionFactory $webhookSubscriptionFactory,
        private readonly DataPersistorInterface $dataPersistor
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $data = $this->getRequest()->getPostValue();
        if (!$data) {
            return $resultRedirect->setPath('*/*/');
        }

        $subscriptionId = (int)($data['subscription_id'] ?? 0);

        try {
            $subscription = $subscriptionId
                ? $this->webhookSubscriptionRepository->getById($subscriptionId)
                : $this->webhookSubscriptionFactory->create();

            $targetUrl = trim((string)($data['target_url'] ?? ''));
            if ($targetUrl === '') {
                throw new LocalizedException(__('The Target URL is required.'));
            }
            if (!filter_var($targetUrl, FILTER_VALIDATE_URL)) {
                throw new LocalizedException(__('The entered Target URL is invalid.'));
            }
            $scheme = strtolower((string)(parse_url($targetUrl, PHP_URL_SCHEME) ?: ''));
            if (!in_array($scheme, ['http', 'https'], true)) {
                throw new LocalizedException(
                    __('The Target URL must use the http or https scheme.')
                );
            }

            $secret = (string)($data['secret'] ?? '');
            if (!$subscriptionId && $secret === '') {
                throw new LocalizedException(__('The HMAC secret is required for new subscriptions.'));
            }

            $storeId = isset($data['store_id']) && $data['store_id'] !== '' ? (int)$data['store_id'] : null;
            $subscription->setStoreId($storeId);
            $subscription->setTargetUrl($targetUrl);
            $subscription->setEnabled((bool)($data['enabled'] ?? false));

            if ($secret !== '') {
                $subscription->setSecret($secret);
            }

            $this->webhookSubscriptionRepository->save($subscription);
            $subscriptionId = (int)$subscription->getSubscriptionId();

            $this->messageManager->addSuccessMessage(__('Webhook subscription saved.'));
            $this->dataPersistor->clear(self::PERSIST_KEY);

            if ($this->getRequest()->getParam('back')) {
                return $resultRedirect->setPath('*/*/edit', ['subscription_id' => $subscriptionId]);
            }

            return $resultRedirect->setPath('*/*/');
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(__('This subscription no longer exists.'));

            return $resultRedirect->setPath('*/*/');
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            $this->messageManager->addExceptionMessage($e, __('Error while saving the webhook subscription.'));
        }

        $this->dataPersistor->set(self::PERSIST_KEY, $data);

        return $subscriptionId
            ? $resultRedirect->setPath('*/*/edit', ['subscription_id' => $subscriptionId])
            : $resultRedirect->setPath('*/*/new');
    }
}
