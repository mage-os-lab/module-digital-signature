<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Template\Source;

use MageOS\DigitalSignature\Api\Data\TemplateInterface;
use Magento\Framework\Data\OptionSourceInterface;

class Scope implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => TemplateInterface::SCOPE_CART, 'label' => __('Carrello/Ordine')],
            ['value' => TemplateInterface::SCOPE_PRODUCT, 'label' => __('Prodotto')],
        ];
    }
}
