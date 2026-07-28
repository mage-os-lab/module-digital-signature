<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Api\Data;

interface WebhookSubscriptionInterface
{
    public const SUBSCRIPTION_ID = 'subscription_id';
    public const STORE_ID = 'store_id';
    public const TARGET_URL = 'target_url';
    public const SECRET = 'secret';
    public const ENABLED = 'enabled';

    public function getSubscriptionId(): ?int;

    public function getStoreId(): ?int;

    public function setStoreId(?int $storeId): self;

    public function getTargetUrl(): string;

    public function setTargetUrl(string $targetUrl): self;

    /** Plaintext value only transient: the resource encrypts it before saving */
    public function getSecret(): ?string;

    public function setSecret(string $secret): self;

    public function getEnabled(): bool;

    public function setEnabled(bool $enabled): self;
}
