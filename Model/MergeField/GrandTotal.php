<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\MergeField;

use MageOS\DigitalSignature\Api\MergeFieldProviderInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;

class GrandTotal implements MergeFieldProviderInterface
{
    public function __construct(
        private readonly PriceCurrencyInterface $priceCurrency
    ) {
    }

    public function getCode(): string
    {
        return 'grand_total';
    }

    public function resolve(Context $context): string
    {
        $order = $context->getOrder();
        return $this->priceCurrency->format(
            (float)$order->getGrandTotal(),
            false,
            PriceCurrencyInterface::DEFAULT_PRECISION,
            $order->getStoreId(),
            $order->getOrderCurrencyCode()
        );
    }

    public function isAvailableForTrigger(string $triggerCode): bool
    {
        return true;
    }
}
