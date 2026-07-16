<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model;

use MageOS\DigitalSignature\Api\Data\TemplateInterface;
use MageOS\DigitalSignature\Model\ResourceModel\Template as TemplateResource;
use Magento\Framework\Model\AbstractModel;

class Template extends AbstractModel implements TemplateInterface
{
    protected $_eventPrefix = 'digitalsignature_template';

    protected function _construct(): void
    {
        $this->_init(TemplateResource::class);
    }

    public function getTemplateId(): ?int
    {
        $id = $this->getData(self::TEMPLATE_ID);
        return $id === null ? null : (int)$id;
    }

    public function setTemplateId(?int $templateId): TemplateInterface
    {
        return $this->setData(self::TEMPLATE_ID, $templateId);
    }

    public function getName(): string
    {
        return (string)$this->getData(self::NAME);
    }

    public function setName(string $name): TemplateInterface
    {
        return $this->setData(self::NAME, $name);
    }

    public function getIsActive(): bool
    {
        return (bool)$this->getData(self::IS_ACTIVE);
    }

    public function setIsActive(bool $isActive): TemplateInterface
    {
        return $this->setData(self::IS_ACTIVE, (int)$isActive);
    }

    public function getIsRequired(): bool
    {
        return (bool)$this->getData(self::IS_REQUIRED);
    }

    public function setIsRequired(bool $isRequired): TemplateInterface
    {
        return $this->setData(self::IS_REQUIRED, (int)$isRequired);
    }

    public function getScope(): string
    {
        return (string)$this->getData(self::SCOPE);
    }

    public function setScope(string $scope): TemplateInterface
    {
        return $this->setData(self::SCOPE, $scope);
    }

    public function getTriggerCode(): ?string
    {
        $trigger = $this->getData(self::TRIGGER_CODE);
        return $trigger === null || $trigger === '' ? null : (string)$trigger;
    }

    public function setTriggerCode(?string $triggerCode): TemplateInterface
    {
        return $this->setData(self::TRIGGER_CODE, $triggerCode);
    }
}
