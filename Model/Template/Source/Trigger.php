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
            ['value' => self::ORDER_PLACED, 'label' => __('Conferma ordine')],
            ['value' => self::INVOICE_CREATED, 'label' => __('Creazione fattura')],
            ['value' => self::INVOICE_PAID, 'label' => __('Fattura pagata')],
            ['value' => self::MANUAL, 'label' => __('Manuale da backend')],
        ];
    }
}
