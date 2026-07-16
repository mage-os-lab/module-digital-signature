<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Block\Adminhtml\Template\Edit;

use Magento\Backend\Block\Widget\Context;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class DeleteButton implements ButtonProviderInterface
{
    public function __construct(protected readonly Context $context)
    {
    }

    public function getButtonData(): array
    {
        $templateId = (int)$this->context->getRequest()->getParam('template_id');
        if (!$templateId) {
            return [];
        }

        $deleteUrl = $this->context->getUrlBuilder()->getUrl('*/*/delete', ['template_id' => $templateId]);

        return [
            'label' => __('Elimina'),
            'class' => 'delete',
            'on_click' => sprintf(
                "deleteConfirm('%s', '%s', {data: {}})",
                __('Eliminare questo template? I documenti già generati restano in storico.'),
                $deleteUrl
            ),
            'sort_order' => 20,
        ];
    }
}
