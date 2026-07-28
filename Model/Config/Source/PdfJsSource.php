<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class PdfJsSource implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'local', 'label' => __('Local (Recommended)')],
            ['value' => 'cdn', 'label' => __('CDN')]
        ];
    }
}
