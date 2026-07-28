<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Plugin\Checkout;

use MageOS\DigitalSignature\Model\Service\SignatureCartContext;
use Magento\Checkout\Block\Checkout\LayoutProcessor;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\UrlInterface;

/**
 * Injects the signature opt-in checkbox into the one-page checkout (Luma),
 * in the "afterMethods" area of the payment step (right above the place
 * order button). The config comes server-side from SignatureCartContext,
 * consistent with cart and minicart; persistence uses the same
 * digitalsignature/cart AJAX endpoint.
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
