<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Block\Adminhtml\System\Config\ProviderInfo;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Riquadro informativo (logo, descrizione estesa, link utili) mostrato in cima
 * al gruppo di configurazione di un provider di firma, in Negozi > Configurazione
 * > Mage-OS > Firma Digitale > Provider di firma.
 *
 * Un'agenzia terza che aggiunge un nuovo provider replica questo pattern:
 * estende questa classe, implementa i 4 metodi astratti e la referenzia come
 * frontend_model di un field id="info" (type="note", nessun valore salvato) in
 * cima al proprio gruppo <group id="miocodice"> in system.xml.
 */
abstract class AbstractProviderInfo extends Field
{
    /** @var string */
    protected $_template = 'MageOS_DigitalSignature::system/config/provider_info.phtml';

    /**
     * Campo "note": nessun controllo di input, solo il riquadro informativo.
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->_toHtml();
    }

    /**
     * URL del logo, tipicamente via {@see getViewFileUrl()} su un asset in
     * view/adminhtml/web/ del proprio modulo (funziona sia in developer mode
     * che dopo static-content:deploy in produzione).
     */
    abstract public function getLogoUrl(): string;

    abstract public function getProviderName(): string;

    abstract public function getDescription(): string;

    abstract public function getLegalLevel(): string;

    /**
     * @return array<int, array{label: string, url: string}>
     */
    abstract public function getLinks(): array;
}
