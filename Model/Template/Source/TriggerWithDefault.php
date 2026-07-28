<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Template\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Like Trigger, but with an empty "use global default" option (for the template form).
 */
class TriggerWithDefault implements OptionSourceInterface
{
    public function __construct(private readonly Trigger $trigger)
    {
    }

    public function toOptionArray(): array
    {
        return array_merge(
            [['value' => '', 'label' => __('-- Use global default --')]],
            $this->trigger->toOptionArray()
        );
    }
}
