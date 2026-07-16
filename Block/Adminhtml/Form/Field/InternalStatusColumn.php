<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Block\Adminhtml\Form\Field;

use MageOS\DigitalSignature\Model\Document\Status;
use Magento\Framework\View\Element\Context;
use Magento\Framework\View\Element\Html\Select;

class InternalStatusColumn extends Select
{
    public function __construct(
        Context $context,
        private readonly Status $status,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function setInputName(string $value): self
    {
        return $this->setData('name', $value);
    }

    public function setInputId(string $value): self
    {
        return $this->setId($value);
    }

    protected function _toHtml(): string
    {
        if (!$this->getOptions()) {
            foreach ($this->status->toOptionArray() as $option) {
                $this->addOption($option['value'], (string)$option['label']);
            }
        }

        return parent::_toHtml();
    }
}
