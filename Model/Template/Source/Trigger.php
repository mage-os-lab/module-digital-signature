<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Template\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Trigger implements OptionSourceInterface
{
    public const ORDER_PLACED = 'order_placed';
    public const INVOICE_CREATED = 'invoice_created';
    public const INVOICE_PAID = 'invoice_paid';
    public const MANUAL = 'manual';

    public function toOptionArray(): array
    {
        return [
            ['value' => self::ORDER_PLACED, 'label' => __('Order confirmation')],
            ['value' => self::INVOICE_CREATED, 'label' => __('Invoice creation')],
            ['value' => self::INVOICE_PAID, 'label' => __('Invoice paid')],
            ['value' => self::MANUAL, 'label' => __('Manual from backend')],
        ];
    }
}
