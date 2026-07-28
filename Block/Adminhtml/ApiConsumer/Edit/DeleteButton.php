<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Block\Adminhtml\ApiConsumer\Edit;

use Magento\Backend\Block\Widget\Context;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class DeleteButton implements ButtonProviderInterface
{
    public function __construct(protected readonly Context $context)
    {
    }

    public function getButtonData(): array
    {
        $consumerId = (int)$this->context->getRequest()->getParam('consumer_id');
        if (!$consumerId) {
            return [];
        }

        $deleteUrl = $this->context->getUrlBuilder()->getUrl('*/*/delete', ['consumer_id' => $consumerId]);

        return [
            'label' => __('Delete'),
            'class' => 'delete',
            'on_click' => sprintf(
                "deleteConfirm('%s', '%s', {data: {}})",
                __('Delete this integration?'),
                $deleteUrl
            ),
            'sort_order' => 20,
        ];
    }
}
