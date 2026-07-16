<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Block\Adminhtml\Template\Edit;

use Magento\Backend\Block\Widget\Context;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class PreviewButton implements ButtonProviderInterface
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
        $storeId = (int)$this->context->getRequest()->getParam('store', 0);
        $previewUrl = $this->context->getUrlBuilder()->getUrl(
            '*/*/preview',
            ['template_id' => $templateId, 'store' => $storeId]
        );

        return [
            'label' => __('Scarica anteprima elaborata'),
            'class' => 'action-secondary',
            'on_click' => sprintf("location.href = '%s';", $previewUrl),
            'sort_order' => 15,
        ];
    }
}
