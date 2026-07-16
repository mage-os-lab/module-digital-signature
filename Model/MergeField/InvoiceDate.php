<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\MergeField;

use MageOS\DigitalSignature\Api\MergeFieldProviderInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

class InvoiceDate implements MergeFieldProviderInterface
{
    public function __construct(
        private readonly TimezoneInterface $timezone
    ) {
    }

    public function getCode(): string
    {
        return 'invoice_date';
    }

    public function resolve(Context $context): string
    {
        $invoice = $context->getInvoice();
        if ($invoice === null) {
            return '';
        }

        return $this->timezone->formatDateTime(
            $invoice->getCreatedAt(),
            \IntlDateFormatter::MEDIUM,
            \IntlDateFormatter::NONE
        );
    }

    public function isAvailableForTrigger(string $triggerCode): bool
    {
        return $triggerCode !== 'order_placed';
    }
}
