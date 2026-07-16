<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Service;

use MageOS\DigitalSignature\Model\ResourceModel\Template as TemplateResource;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Logica condivisa (ViewModel Luma/Hyva + customer-data section minicart) che
 * decide, per il carrello corrente, se l'opt-in firma è applicabile, se è
 * obbligatorio e qual è lo stato della scelta cliente. Stessa logica di
 * applicabilità usata server-side dal TriggerHandler.
 */
class SignatureCartContext
{
    private const XML_PATH_ENABLED = 'digital_signature/general/enabled';
    private const XML_PATH_PROVIDER = 'digital_signature/general/provider';

    /** Cache per-request dell'analisi del carrello */
    private ?array $analysis = null;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly CheckoutSession $checkoutSession,
        private readonly TemplateResource $templateResource
    ) {
    }

    /**
     * Modulo attivo e provider configurato sullo store corrente.
     */
    public function isEnabled(): bool
    {
        if (!$this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE)) {
            return false;
        }

        return (string)$this->scopeConfig->getValue(self::XML_PATH_PROVIDER, ScopeInterface::SCOPE_STORE) !== '';
    }

    /**
     * Esiste almeno un template (obbligatorio o facoltativo) applicabile al
     * carrello corrente.
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
     * Almeno un template applicabile è obbligatorio (checkbox informativo,
     * spuntato e non deselezionabile). Enforcement comunque server-side.
     */
    public function isMandatory(): bool
    {
        return $this->analyze()['hasRequired'];
    }

    /**
     * Stato corrente della spunta (tri-stato sul quote):
     * - NULL/'' (mai espressa): obbligatori pre-spuntati, facoltativi no;
     * - 0: deselezionata esplicitamente dal cliente → resta deselezionata;
     * - 1: selezionata.
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
        return (string)__('Voglio ricevere il contratto da firmare digitalmente');
    }

    public function getNote(): string
    {
        return (string)__('Per i prodotti nel carrello la firma del contratto è obbligatoria.');
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
            // In caso di errore non mostriamo nulla
        }

        return $this->analysis = ['hasOptional' => $hasOptional, 'hasRequired' => $hasRequired];
    }
}
