<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class AuditTrailDataCollection implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => '', 'label' => __('-- Please choose --')],
            ['value' => '1', 'label' => __('Yes - collect and retain audit trail data')],
            ['value' => '0', 'label' => __('No - do not collect additional audit trail data')],
        ];
    }
}
