<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\ViewModel;

use MageOS\DigitalSignature\Model\Service\SignatureCartContext;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * ViewModel del checkbox opt-in (Luma e Hyva, pagina carrello). Delega a
 * SignatureCartContext la logica di applicabilità, condivisa con la
 * customer-data section della minicart.
 */
class SignatureRequest implements ArgumentInterface
{
    public function __construct(
        private readonly SignatureCartContext $context,
        private readonly UrlInterface $urlBuilder,
        private readonly FormKey $formKey
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->context->isEnabled();
    }

    public function isApplicable(): bool
    {
        return $this->context->isApplicable();
    }

    public function isMandatory(): bool
    {
        return $this->context->isMandatory();
    }

    public function isRequested(): bool
    {
        return $this->context->isRequested();
    }

    public function getLabel(): string
    {
        return $this->context->getLabel();
    }

    public function getNote(): string
    {
        return $this->context->getNote();
    }

    public function getEndpointUrl(): string
    {
        return $this->urlBuilder->getUrl('digitalsignature/cart/setRequested');
    }

    public function getFormKey(): string
    {
        return $this->formKey->getFormKey();
    }
}
