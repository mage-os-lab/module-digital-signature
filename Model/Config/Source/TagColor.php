<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class TagColor implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => '1 g', 'label' => __('White (Invisible)')],
            ['value' => '0 g', 'label' => __('Black')],
            ['value' => '0.8 g', 'label' => __('Light Gray')],
            ['value' => '1 0 0 rg', 'label' => __('Red')],
        ];
    }
}
