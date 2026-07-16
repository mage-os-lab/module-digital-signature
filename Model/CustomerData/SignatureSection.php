<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\CustomerData;

use MageOS\DigitalSignature\Model\Service\SignatureCartContext;
use Magento\Customer\CustomerData\SectionSourceInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\UrlInterface;

/**
 * Espone alla minicart (KO Luma / Alpine Hyva, via customer-data) lo stato
 * dell'opt-in firma per il carrello corrente. Reattiva: invalidata sulle
 * azioni di carrello e dopo il salvataggio della scelta (vedi sections.xml).
 */
class SignatureSection implements SectionSourceInterface
{
    public function __construct(
        private readonly SignatureCartContext $context,
        private readonly UrlInterface $urlBuilder,
        private readonly FormKey $formKey
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getSectionData(): array
    {
        if (!$this->context->isApplicable()) {
            return ['applicable' => false];
        }

        return [
            'applicable' => true,
            'mandatory' => $this->context->isMandatory(),
            'requested' => $this->context->isRequested(),
            'url' => $this->urlBuilder->getUrl('digitalsignature/cart/setRequested'),
            'formKey' => $this->formKey->getFormKey(),
            'label' => $this->context->getLabel(),
            'note' => $this->context->getNote(),
        ];
    }
}
