<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\MergeField;

use MageOS\DigitalSignature\Api\MergeFieldProviderInterface;

class CustomerName implements MergeFieldProviderInterface
{
    public function getCode(): string
    {
        return 'customer_name';
    }

    public function resolve(Context $context): string
    {
        $order = $context->getOrder();
        return trim((string)$order->getCustomerFirstname() . ' ' . (string)$order->getCustomerLastname());
    }

    public function isAvailableForTrigger(string $triggerCode): bool
    {
        return true;
    }
}
