<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Service;

use MageOS\DigitalSignature\Api\DocumentGenerationManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

class DocumentGenerationManagement implements DocumentGenerationManagementInterface
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly DocumentManager $documentManager,
        private readonly ApiConsumerScope $apiConsumerScope
    ) {
    }

    public function generate(int $orderId): void
    {
        $this->apiConsumerScope->assertOrderInScope($orderId);
        $order = $this->orderRepository->get($orderId);
        $this->documentManager->generateManual($order);
    }

    public function regenerate(int $documentId): int
    {
        // DocumentManager::regenerate() internally calls
        // DocumentRepository::getById(), already filtered by DocumentScopePlugin
        // (Task 6): a document out of scope surfaces as NoSuchEntityException.
        return $this->documentManager->regenerate($documentId);
    }
}
