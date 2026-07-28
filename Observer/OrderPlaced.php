<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Observer;

use MageOS\DigitalSignature\Model\Service\TriggerHandler;
use MageOS\DigitalSignature\Model\Template\Source\Trigger;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;

class OrderPlaced implements ObserverInterface
{
    public function __construct(
        private readonly TriggerHandler $triggerHandler,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');
        if (!$order instanceof OrderInterface || !$order->getEntityId()) {
            return;
        }
        try {
            $this->triggerHandler->handle($order, Trigger::ORDER_PLACED);
        } catch (\Exception $e) {
            // Never block order placement because of a module error
            $this->logger->error('DigitalSignature: errore trigger conferma ordine: ' . $e->getMessage());
        }
    }
}
