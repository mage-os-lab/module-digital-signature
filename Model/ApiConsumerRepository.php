<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model;

use MageOS\DigitalSignature\Api\ApiConsumerRepositoryInterface;
use MageOS\DigitalSignature\Api\Data\ApiConsumerInterface;
use MageOS\DigitalSignature\Model\ResourceModel\ApiConsumer as ApiConsumerResource;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;

class ApiConsumerRepository implements ApiConsumerRepositoryInterface
{
    public function __construct(
        private readonly ApiConsumerResource $resource,
        private readonly ApiConsumerFactory $apiConsumerFactory
    ) {
    }

    public function save(ApiConsumerInterface $consumer): ApiConsumerInterface
    {
        try {
            $this->resource->save($consumer);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(
                __('Unable to save the API integration: %1', $e->getMessage()),
                $e
            );
        }

        return $consumer;
    }

    public function getById(int $consumerId): ApiConsumerInterface
    {
        $consumer = $this->apiConsumerFactory->create();
        $this->resource->load($consumer, $consumerId);
        if (!$consumer->getConsumerId()) {
            throw new NoSuchEntityException(__('API integration with id "%1" does not exist.', $consumerId));
        }

        return $consumer;
    }

    public function getByIntegrationId(int $integrationId): ApiConsumerInterface
    {
        $consumer = $this->apiConsumerFactory->create();
        $this->resource->loadByIntegrationId($consumer, $integrationId);
        if (!$consumer->getConsumerId()) {
            throw new NoSuchEntityException(
                __('No API integration mapped for integration "%1".', $integrationId)
            );
        }

        return $consumer;
    }

    public function getStoreIds(int $consumerId): array
    {
        return $this->resource->getStoreIds($consumerId);
    }

    public function saveStoreIds(int $consumerId, array $storeIds): void
    {
        $this->resource->saveStoreIds($consumerId, $storeIds);
    }

    public function delete(ApiConsumerInterface $consumer): void
    {
        try {
            $this->resource->delete($consumer);
        } catch (\Exception $e) {
            throw new CouldNotDeleteException(
                __('Unable to delete the API integration: %1', $e->getMessage()),
                $e
            );
        }
    }
}
