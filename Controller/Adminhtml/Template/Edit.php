<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Controller\Adminhtml\Template;

use MageOS\DigitalSignature\Api\TemplateRepositoryInterface;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Result\PageFactory;

class Edit extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_DigitalSignature::template';

    public function __construct(
        Action\Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly TemplateRepositoryInterface $templateRepository
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $templateId = (int)$this->getRequest()->getParam('template_id');
        if ($templateId) {
            try {
                $template = $this->templateRepository->getById($templateId);
                $title = __('Edit template "%1"', $template->getName());
            } catch (NoSuchEntityException) {
                $this->messageManager->addErrorMessage(__('This template no longer exists.'));
                $resultRedirect = $this->resultRedirectFactory->create();

                return $resultRedirect->setPath('*/*/');
            }
        } else {
            $title = __('New document template');
        }

        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('MageOS_DigitalSignature::template');
        $resultPage->getConfig()->getTitle()->prepend($title);

        return $resultPage;
    }
}
