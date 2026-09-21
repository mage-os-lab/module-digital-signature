<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Block\Adminhtml\System\Config\ProviderInfo;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Info box (logo, extended description, useful links) shown at the top of a
 * signature provider's configuration group, in Stores > Configuration
 * > Mage-OS > Digital Signature > Signature Providers.
 *
 * A third-party agency adding a new provider replicates this pattern:
 * extend this class, implement the 4 abstract methods and reference it as
 * frontend_model of a field id="info" (type="note", no saved value) at
 * the top of its own <group id="mycode"> in system.xml.
 */
abstract class AbstractProviderInfo extends Field
{
    /** @var string */
    protected $_template = 'MageOS_DigitalSignature::system/config/provider_info.phtml';

    /**
     * "Note" field: no input control, just the info box.
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->_toHtml();
    }

    /**
     * Logo URL, typically via {@see getViewFileUrl()} on an asset in
     * view/adminhtml/web/ of the module (works both in developer mode
     * and after static-content:deploy in production).
     */
    abstract public function getLogoUrl(): string;

    abstract public function getProviderName(): string;

    abstract public function getDescription(): string;

    abstract public function getLegalLevel(): string;

    /**
     * @return array<int, array{label: string, url: string}>
     */
    abstract public function getLinks(): array;

    /**
     * If true, the provider's box is highlighted with a "Recommended" badge.
     */
    public function isRecommended(): bool
    {
        return false;
    }

    /**
     * Marketing text shown in the highlighted box when {@see isRecommended()} is true.
     */
    public function getRecommendationText(): ?string
    {
        return null;
    }

    /**
     * Warning shown in the box when the provider is configured in a non-production
     * environment (e.g. DocuSign demo/sandbox). Null = no warning for this provider.
     */
    public function getDemoWarning(): ?string
    {
        return null;
    }
}
