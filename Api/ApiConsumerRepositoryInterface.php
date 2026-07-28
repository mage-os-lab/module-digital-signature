<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Api;

use MageOS\DigitalSignature\Api\Data\ApiConsumerInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;

interface ApiConsumerRepositoryInterface
{
    /**
     * @throws CouldNotSaveException
     */
    public function save(ApiConsumerInterface $consumer): ApiConsumerInterface;

    /**
     * @throws NoSuchEntityException
     */
    public function getById(int $consumerId): ApiConsumerInterface;

    /**
     * @throws NoSuchEntityException if no integration is mapped
     */
    public function getByIntegrationId(int $integrationId): ApiConsumerInterface;

    /**
     * @return int[]
     */
    public function getStoreIds(int $consumerId): array;

    /**
     * @param int[] $storeIds
     */
    public function saveStoreIds(int $consumerId, array $storeIds): void;

    /**
     * @throws \Magento\Framework\Exception\CouldNotDeleteException
     */
    public function delete(ApiConsumerInterface $consumer): void;
}
