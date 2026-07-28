<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Api\Data;

interface ApiConsumerInterface
{
    public const CONSUMER_ID = 'consumer_id';
    public const INTEGRATION_ID = 'integration_id';
    public const NAME = 'name';
    public const ENABLED = 'enabled';

    public function getConsumerId(): ?int;

    public function getIntegrationId(): int;

    public function setIntegrationId(int $integrationId): self;

    public function getName(): string;

    public function setName(string $name): self;

    public function getEnabled(): bool;

    public function setEnabled(bool $enabled): self;
}
