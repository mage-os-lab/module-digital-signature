<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\MergeField;

use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Api\Data\OrderInterface;

class Context
{
    public function __construct(
        private readonly OrderInterface $order,
        private readonly ?InvoiceInterface $invoice = null,
        private readonly ?int $storeId = null
    ) {
    }

    public function getOrder(): OrderInterface
    {
        return $this->order;
    }

    public function getInvoice(): ?InvoiceInterface
    {
        return $this->invoice;
    }

    public function getStoreId(): int
    {
        return $this->storeId !== null ? $this->storeId : (int)$this->order->getStoreId();
    }
}
