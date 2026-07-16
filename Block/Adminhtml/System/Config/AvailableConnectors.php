<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Block\Adminhtml\System\Config;

use MageOS\DigitalSignature\Model\Connector\AvailableConnectorsCatalog;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class AvailableConnectors extends Field
{
    protected $_template = 'MageOS_DigitalSignature::system/config/available_connectors.phtml';

    public function __construct(
        Context $context,
        private readonly AvailableConnectorsCatalog $catalog,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->_toHtml();
    }

    /**
     * @return \MageOS\DigitalSignature\Model\Connector\AvailableConnector[]
     */
    public function getConnectors(): array
    {
        return $this->catalog->getAll();
    }

    public function getDeveloperGuideUrl(): string
    {
        return 'https://github.com/mage-os/mage-os-module-firma-digitale/blob/main/docs/sign-provider-integration.md';
    }
}
