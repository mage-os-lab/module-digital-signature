<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\MergeField;

use MageOS\DigitalSignature\Api\MergeFieldProviderInterface;

class OrderNumber implements MergeFieldProviderInterface
{
    public function getCode(): string
    {
        return 'order_number';
    }

    public function resolve(Context $context): string
    {
        return (string)$context->getOrder()->getIncrementId();
    }

    public function isAvailableForTrigger(string $triggerCode): bool
    {
        return true;
    }
}
