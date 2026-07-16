<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Pdf;

use MageOS\DigitalSignature\Model\MergeField\Context;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Order\InvoiceFactory;

class PreviewOrderContext
{
    public function __construct(
        private readonly OrderFactory $orderFactory,
        private readonly InvoiceFactory $invoiceFactory
    ) {
    }

    public function getContext(): Context
    {
        /** @var \Magento\Sales\Model\Order $order */
        $order = $this->orderFactory->create();
        $order->setIncrementId('100000001');
        $order->setGrandTotal(123.45);
        $order->setCustomerFirstname('Mario');
        $order->setCustomerLastname('Rossi');
        $order->setCreatedAt('2026-07-14 15:30:00');
        $order->setOrderCurrencyCode('EUR');
        $order->setStoreId(0); // Admin / Default Store

        /** @var \Magento\Sales\Model\Order\Invoice $invoice */
        $invoice = $this->invoiceFactory->create();
        $invoice->setCreatedAt('2026-07-14 16:00:00');
        $invoice->setOrder($order);

        return new Context($order, $invoice);
    }
}
