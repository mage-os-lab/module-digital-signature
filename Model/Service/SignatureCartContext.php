<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Service;

use MageOS\DigitalSignature\Model\ResourceModel\Template as TemplateResource;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Shared logic (Luma/Hyva ViewModel + customer-data minicart section) that
 * decides, for the current cart, whether the signature opt-in is applicable,
 * whether it is mandatory, and what the customer's choice status is. Same
 * applicability logic used server-side by TriggerHandler.
 */
class SignatureCartContext
{
    private const XML_PATH_ENABLED = 'digital_signature/general/enabled';
    private const XML_PATH_PROVIDER = 'digital_signature/general/provider';

    /** Per-request cache of the cart analysis */
    private ?array $analysis = null;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly CheckoutSession $checkoutSession,
        private readonly TemplateResource $templateResource
    ) {
    }

    /**
     * Module active and provider configured on the current store.
     */
    public function isEnabled(): bool
    {
        if (!$this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE)) {
            return false;
        }

        return (string)$this->scopeConfig->getValue(self::XML_PATH_PROVIDER, ScopeInterface::SCOPE_STORE) !== '';
    }

    /**
     * At least one template (mandatory or optional) applicable to the
     * current cart exists.
     */
    public function isApplicable(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        $analysis = $this->analyze();

        return $analysis['hasOptional'] || $analysis['hasRequired'];
    }

    /**
     * At least one applicable template is mandatory (informational checkbox,
     * checked and not deselectable). Enforcement is still server-side.
     */
    public function isMandatory(): bool
    {
        return $this->analyze()['hasRequired'];
    }

    /**
     * Current status of the checkbox (tri-state on the quote):
     * - NULL/'' (never expressed): mandatory pre-checked, optional not;
     * - 0: explicitly unchecked by the customer → stays unchecked;
     * - 1: checked.
     */
    public function isRequested(): bool
    {
        try {
            $raw = $this->checkoutSession->getQuote()->getData('digitalsignature_requested');
        } catch (\Exception $e) {
            $raw = null;
        }
        if ($raw === null || $raw === '') {
            return $this->isMandatory();
        }

        return (bool)(int)$raw;
    }

    public function getLabel(): string
    {
        return (string)__('I want to receive the contract to sign digitally');
    }

    public function getNote(): string
    {
        return (string)__('Signing the contract is mandatory for the products in your cart.');
    }

    /**
     * @return array{hasOptional: bool, hasRequired: bool}
     */
    private function analyze(): array
    {
        if ($this->analysis !== null) {
            return $this->analysis;
        }
        $hasOptional = false;
        $hasRequired = false;

        try {
            $quote = $this->checkoutSession->getQuote();

            foreach ($this->templateResource->getActiveCartTemplateRows() as $row) {
                if ((bool)($row['is_required'] ?? false)) {
                    $hasRequired = true;
                } else {
                    $hasOptional = true;
                }
            }

            $productIds = [];
            foreach ($quote->getAllVisibleItems() as $item) {
                $productIds[(int)$item->getProductId()] = true;
            }
            if ($productIds) {
                foreach ($this->templateResource->getProductAssignmentRows(array_keys($productIds)) as $row) {
                    if ((bool)($row['is_required'] ?? false)) {
                        $hasRequired = true;
                    } else {
                        $hasOptional = true;
                    }
                }
            }
        } catch (\Exception $e) {
            // In case of error we show nothing
        }

        return $this->analysis = ['hasOptional' => $hasOptional, 'hasRequired' => $hasRequired];
    }
}
