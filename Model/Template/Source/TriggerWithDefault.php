<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Template\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Come Trigger, ma con opzione vuota "usa default globale" (per form template).
 */
class TriggerWithDefault implements OptionSourceInterface
{
    public function __construct(private readonly Trigger $trigger)
    {
    }

    public function toOptionArray(): array
    {
        return array_merge(
            [['value' => '', 'label' => __('-- Usa default globale --')]],
            $this->trigger->toOptionArray()
        );
    }
}
