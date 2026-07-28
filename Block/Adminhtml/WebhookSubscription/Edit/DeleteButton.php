<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Block\Adminhtml\WebhookSubscription\Edit;

use Magento\Backend\Block\Widget\Context;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class DeleteButton implements ButtonProviderInterface
{
    public function __construct(protected readonly Context $context)
    {
    }

    public function getButtonData(): array
    {
        $id = (int)$this->context->getRequest()->getParam('subscription_id');
        if (!$id) {
            return [];
        }

        return [
            'label' => __('Delete'),
            'class' => 'delete',
            'on_click' => sprintf(
                "deleteConfirm('%s', '%s')",
                __('Are you sure you want to delete this webhook subscription?'),
                $this->context->getUrlBuilder()->getUrl('*/*/delete', ['subscription_id' => $id])
            ),
            'sort_order' => 20,
        ];
    }
}
