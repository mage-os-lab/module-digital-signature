<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Controller\Adminhtml\Template;

use MageOS\DigitalSignature\Api\TemplateRepositoryInterface;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;

class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_DigitalSignature::template';

    public function __construct(
        Action\Context $context,
        private readonly TemplateRepositoryInterface $templateRepository
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $templateId = (int)$this->getRequest()->getParam('template_id');
        if (!$templateId) {
            $this->messageManager->addErrorMessage(__('Nessun template selezionato.'));

            return $resultRedirect->setPath('*/*/');
        }

        try {
            $this->templateRepository->deleteById($templateId);
            $this->messageManager->addSuccessMessage(__('Template eliminato.'));
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage($e->getMessage());

            return $resultRedirect->setPath('*/*/edit', ['template_id' => $templateId]);
        }

        return $resultRedirect->setPath('*/*/');
    }
}
