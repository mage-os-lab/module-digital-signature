<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Warns on the template grid/edit pages when the module is not yet enabled
 * or has no provider configured: templates and product assignments work
 * regardless, but the signature checkbox and generation stay silently off
 * on the storefront until Stores > Configuration > Digital Signature is set,
 * which is easy to miss on a first setup.
 */
class ConfigurationWarning extends Template
{
    private const XML_PATH_ENABLED = 'digital_signature/general/enabled';
    private const XML_PATH_PROVIDER = 'digital_signature/general/provider';

    protected $_template = 'MageOS_DigitalSignature::configuration_warning.phtml';

    public function __construct(
        Context $context,
        private readonly ScopeConfigInterface $scopeConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function isConfigured(): bool
    {
        if (!$this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE)) {
            return false;
        }

        return (string)$this->scopeConfig->getValue(self::XML_PATH_PROVIDER, ScopeInterface::SCOPE_STORE) !== '';
    }

    public function getConfigurationUrl(): string
    {
        return $this->getUrl('adminhtml/system_config/edit', ['section' => 'digital_signature']);
    }
}
