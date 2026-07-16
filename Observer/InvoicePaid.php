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
            // sales_order_invoice_pay copre capture online e fatture offline (analisi §4)
            $this->triggerHandler->handle($invoice->getOrder(), Trigger::INVOICE_PAID);
        } catch (\Exception $e) {
            $this->logger->error('DigitalSignature: errore trigger fattura pagata: ' . $e->getMessage());
        }
    }
}
