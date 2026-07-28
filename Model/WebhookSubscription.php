<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model;

use MageOS\DigitalSignature\Api\Data\WebhookSubscriptionInterface;
use MageOS\DigitalSignature\Model\ResourceModel\WebhookSubscription as WebhookSubscriptionResource;
use Magento\Framework\Model\AbstractModel;

class WebhookSubscription extends AbstractModel implements WebhookSubscriptionInterface
{
    protected $_eventPrefix = 'digitalsignature_webhook_subscription';

    protected function _construct(): void
    {
        $this->_init(WebhookSubscriptionResource::class);
    }

    public function getSubscriptionId(): ?int
    {
        $id = $this->getData(self::SUBSCRIPTION_ID);

        return $id === null ? null : (int)$id;
    }

    public function getStoreId(): ?int
    {
        $storeId = $this->getData(self::STORE_ID);

        return $storeId === null || $storeId === '' ? null : (int)$storeId;
    }

    public function setStoreId(?int $storeId): WebhookSubscriptionInterface
    {
        return $this->setData(self::STORE_ID, $storeId);
    }

    public function getTargetUrl(): string
    {
        return (string)$this->getData(self::TARGET_URL);
    }

    public function setTargetUrl(string $targetUrl): WebhookSubscriptionInterface
    {
        return $this->setData(self::TARGET_URL, $targetUrl);
    }

    public function getSecret(): ?string
    {
        $secret = $this->getData(self::SECRET);

        return $secret === null ? null : (string)$secret;
    }

    public function setSecret(string $secret): WebhookSubscriptionInterface
    {
        return $this->setData(self::SECRET, $secret);
    }

    public function getEnabled(): bool
    {
        return (bool)$this->getData(self::ENABLED);
    }

    public function setEnabled(bool $enabled): WebhookSubscriptionInterface
    {
        return $this->setData(self::ENABLED, $enabled ? 1 : 0);
    }
}
