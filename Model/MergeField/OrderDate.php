<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\MergeField;

use MageOS\DigitalSignature\Api\MergeFieldProviderInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

class OrderDate implements MergeFieldProviderInterface
{
    public function __construct(
        private readonly TimezoneInterface $timezone
    ) {
    }

    public function getCode(): string
    {
        return 'order_date';
    }

    public function resolve(Context $context): string
    {
        $order = $context->getOrder();
        return $this->timezone->formatDateTime(
            $order->getCreatedAt(),
            \IntlDateFormatter::MEDIUM,
            \IntlDateFormatter::NONE
        );
    }

    public function isAvailableForTrigger(string $triggerCode): bool
    {
        return true;
    }
}
