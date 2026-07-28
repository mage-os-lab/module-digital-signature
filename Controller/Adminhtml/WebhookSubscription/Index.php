<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Controller\Adminhtml\WebhookSubscription;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_DigitalSignature::webhook_subscription';

    public function __construct(
        Action\Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): Page
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('MageOS_DigitalSignature::webhook_subscription');
        $resultPage->getConfig()->getTitle()->prepend(__('Webhook Subscriptions'));

        return $resultPage;
    }
}
