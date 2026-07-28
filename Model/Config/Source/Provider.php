<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Config\Source;

use MageOS\DigitalSignature\Api\SignProviderPoolInterface;
use Magento\Framework\Data\OptionSourceInterface;

class Provider implements OptionSourceInterface
{
    public function __construct(private readonly SignProviderPoolInterface $providerPool)
    {
    }

    public function toOptionArray(): array
    {
        $options = [['value' => '', 'label' => __('-- None --')]];
        foreach ($this->providerPool->getProviders() as $code => $provider) {
            $options[] = ['value' => $code, 'label' => $provider->getLabel()];
        }

        return $options;
    }
}
