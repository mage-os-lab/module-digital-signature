<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Observer;

use MageOS\DigitalSignature\Model\Service\TriggerHandler;
use MageOS\DigitalSignature\Model\Template\Source\Trigger;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order\Invoice;
use Psr\Log\LoggerInterface;

class InvoiceCreated implements ObserverInterface
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
        // Only on first creation: subsequent saves are not "invoice creation"
        if (!$invoice->isObjectNew() && $invoice->getOrigData('entity_id')) {
            return;
        }
        try {
            $this->triggerHandler->handle($invoice->getOrder(), Trigger::INVOICE_CREATED);
        } catch (\Exception $e) {
            $this->logger->error('DigitalSignature: errore trigger creazione fattura: ' . $e->getMessage());
        }
    }
}
