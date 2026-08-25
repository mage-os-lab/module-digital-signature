<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ExceededBehavior implements OptionSourceInterface
{
    public const ALLOW_AND_LOG = 'allow_and_log';
    public const BLOCK_DISPATCH = 'block_dispatch';

    public function toOptionArray(): array
    {
        return [
            [
                'value' => self::ALLOW_AND_LOG,
                'label' => __('Allow dispatch and log warning')
            ],
            [
                'value' => self::BLOCK_DISPATCH,
                'label' => __('Block new signature requests')
            ]
        ];
    }
}
