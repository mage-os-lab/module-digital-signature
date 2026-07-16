<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Api\Data;

interface TemplateInterface
{
    public const TEMPLATE_ID = 'template_id';
    public const NAME = 'name';
    public const IS_ACTIVE = 'is_active';
    public const IS_REQUIRED = 'is_required';
    public const SCOPE = 'scope';
    public const TRIGGER_CODE = 'trigger_code';
    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'updated_at';

    public const SCOPE_CART = 'cart';
    public const SCOPE_PRODUCT = 'product';

    public function getTemplateId(): ?int;

    public function setTemplateId(?int $templateId): self;

    public function getName(): string;

    public function setName(string $name): self;

    public function getIsActive(): bool;

    public function setIsActive(bool $isActive): self;

    /**
     * Se true il documento viene sempre generato, indipendentemente dalla
     * scelta del cliente al checkout.
     */
    public function getIsRequired(): bool;

    public function setIsRequired(bool $isRequired): self;

    public function getScope(): string;

    public function setScope(string $scope): self;

    /**
     * Trigger di generazione; null = usa il default globale di configurazione.
     */
    public function getTriggerCode(): ?string;

    public function setTriggerCode(?string $triggerCode): self;
}
