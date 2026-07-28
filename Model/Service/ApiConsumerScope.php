<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Service;

use MageOS\DigitalSignature\Api\ApiConsumerRepositoryInterface;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Resolves the store scope of the API caller: Magento integrations that are
 * not explicitly mapped (or are disabled) see no documents at all
 * (fail-closed). Non-integration callers (e.g. admin token) remain
 * unrestricted: this feature only limits external integrations.
 */
class ApiConsumerScope
{
    public function __construct(
        private readonly UserContextInterface $userContext,
        private readonly ApiConsumerRepositoryInterface $consumerRepository,
        private readonly OrderRepositoryInterface $orderRepository
    ) {
    }

    /**
     * @return int[]|null null = no restriction, array (even empty) =
     *                     stores allowed for the calling integration
     */
    public function getAllowedStoreIds(): ?array
    {
        if ($this->userContext->getUserType() !== UserContextInterface::USER_TYPE_INTEGRATION) {
            return null;
        }
        $integrationId = $this->userContext->getUserId();
        if ($integrationId === null) {
            return [];
        }
        try {
            $consumer = $this->consumerRepository->getByIntegrationId((int)$integrationId);
        } catch (NoSuchEntityException) {
            return [];
        }
        if (!$consumer->getEnabled()) {
            return [];
        }

        return $this->consumerRepository->getStoreIds((int)$consumer->getConsumerId());
    }

    public function isStoreAllowed(int $storeId): bool
    {
        $allowed = $this->getAllowedStoreIds();

        return $allowed === null || in_array($storeId, $allowed, true);
    }

    /**
     * @throws NoSuchEntityException if the order is not within the caller's scope
     */
    public function assertOrderInScope(int $orderId): void
    {
        $order = $this->orderRepository->get($orderId);
        if (!$this->isStoreAllowed((int)$order->getStoreId())) {
            throw new NoSuchEntityException(__('Order with id "%1" does not exist.', $orderId));
        }
    }
}
