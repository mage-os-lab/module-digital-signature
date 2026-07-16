<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Plugin\Checkout;

use MageOS\DigitalSignature\Model\Service\SignatureCartContext;
use Magento\Checkout\Block\Checkout\LayoutProcessor;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\UrlInterface;

/**
 * Inietta il checkbox opt-in firma nel checkout one-page (Luma), nell'area
 * "afterMethods" dello step di pagamento (subito sopra il place order). La
 * config arriva server-side da SignatureCartContext, coerente con cart e
 * minicart; la persistenza usa lo stesso endpoint AJAX digitalsignature/cart.
 */
class LayoutProcessorPlugin
{
    public function __construct(
        private readonly SignatureCartContext $context,
        private readonly UrlInterface $urlBuilder,
        private readonly FormKey $formKey
    ) {
    }

    /**
     * @param array<string, mixed> $jsLayout
     * @return array<string, mixed>
     */
    public function afterProcess(LayoutProcessor $subject, array $jsLayout): array
    {
        if (!$this->context->isApplicable()) {
            return $jsLayout;
        }

        $payment = &$jsLayout['components']['checkout']['children']['steps']['children']['billing-step']
            ['children']['payment']['children'];

        if (!isset($payment['afterMethods'])) {
            $payment['afterMethods'] = [
                'component' => 'uiComponent',
                'displayArea' => 'afterMethods',
                'children' => [],
            ];
        }

        $payment['afterMethods']['children']['digitalsignature-signature'] = [
            'component' => 'MageOS_DigitalSignature/js/view/checkout-signature',
            'sortOrder' => 100,
            'config' => [
                'template' => 'MageOS_DigitalSignature/checkout/signature',
                'mandatory' => $this->context->isMandatory(),
                'requested' => $this->context->isRequested(),
                'url' => $this->urlBuilder->getUrl('digitalsignature/cart/setRequested'),
                'formKey' => $this->formKey->getFormKey(),
                'label' => $this->context->getLabel(),
                'note' => $this->context->getNote(),
            ],
        ];

        return $jsLayout;
    }
}
