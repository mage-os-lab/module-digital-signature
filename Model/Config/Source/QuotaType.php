<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use MageOS\DigitalSignature\Api\Data\QuotaStatusInterface;

class QuotaType implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            [
                'value' => QuotaStatusInterface::QUOTA_TYPE_PREPAID,
                'label' => __('Prepaid Credit Package (Pool)')
            ],
            [
                'value' => QuotaStatusInterface::QUOTA_TYPE_MONTHLY,
                'label' => __('Monthly Allowance')
            ],
            [
                'value' => QuotaStatusInterface::QUOTA_TYPE_ANNUAL,
                'label' => __('Annual Allowance (Commitment)')
            ]
        ];
    }
}
