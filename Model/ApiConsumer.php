<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model;

use MageOS\DigitalSignature\Api\Data\ApiConsumerInterface;
use MageOS\DigitalSignature\Model\ResourceModel\ApiConsumer as ApiConsumerResource;
use Magento\Framework\Model\AbstractModel;

class ApiConsumer extends AbstractModel implements ApiConsumerInterface
{
    protected $_eventPrefix = 'digitalsignature_api_consumer';

    protected function _construct(): void
    {
        $this->_init(ApiConsumerResource::class);
    }

    public function getConsumerId(): ?int
    {
        $id = $this->getData(self::CONSUMER_ID);

        return $id === null ? null : (int)$id;
    }

    public function getIntegrationId(): int
    {
        return (int)$this->getData(self::INTEGRATION_ID);
    }

    public function setIntegrationId(int $integrationId): ApiConsumerInterface
    {
        return $this->setData(self::INTEGRATION_ID, $integrationId);
    }

    public function getName(): string
    {
        return (string)$this->getData(self::NAME);
    }

    public function setName(string $name): ApiConsumerInterface
    {
        return $this->setData(self::NAME, $name);
    }

    public function getEnabled(): bool
    {
        return (bool)$this->getData(self::ENABLED);
    }

    public function setEnabled(bool $enabled): ApiConsumerInterface
    {
        return $this->setData(self::ENABLED, $enabled ? 1 : 0);
    }
}
