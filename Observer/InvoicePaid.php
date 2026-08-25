<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Observer;

use MageOS\DigitalSignature\Model\Service\TriggerHandler;
use MageOS\DigitalSignature\Model\Template\Source\Trigger;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order\Invoice;
use Psr\Log\LoggerInterface;

class InvoicePaid implements ObserverInterface
{
    public function __construct(
        private readonly TriggerHandler $triggerHandler,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $invoice = $observer->getEvent()->getData('invoice');
        if (!$invoice instanceof Invoice) {
            return;
        }
        try {
            // sales_order_invoice_pay covers both online capture and offline invoices (see §4 analysis)
            $this->triggerHandler->handle($invoice->getOrder(), Trigger::INVOICE_PAID);
        } catch (\Exception $e) {
            $this->logger->error('DigitalSignature: invoice paid trigger error: ' . $e->getMessage());
        }
    }
}
