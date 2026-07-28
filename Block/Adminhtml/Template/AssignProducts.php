<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Block\Adminhtml\Template;

use MageOS\DigitalSignature\Block\Adminhtml\Template\Tab\Product as ProductGrid;
use MageOS\DigitalSignature\Model\ResourceModel\Template as TemplateResource;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Grid + serializer wrapper for product assignment in the template form.
 */
class AssignProducts extends Template
{
    protected $_template = 'MageOS_DigitalSignature::template/assign_products.phtml';

    private ?ProductGrid $blockGrid = null;

    public function __construct(
        Context $context,
        private readonly TemplateResource $templateResource,
        private readonly Json $jsonSerializer,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getBlockGrid(): ProductGrid
    {
        if ($this->blockGrid === null) {
            $this->blockGrid = $this->getLayout()->createBlock(
                ProductGrid::class,
                'digitalsignature.template.products.grid'
            );
        }

        return $this->blockGrid;
    }

    public function getGridHtml(): string
    {
        return $this->getBlockGrid()->toHtml();
    }

    public function getProductsJson(): string
    {
        return $this->jsonSerializer->serialize($this->getAssignedProducts());
    }

    /**
     * Safe JSON for direct insertion into a <script> block (no @noEscape):
     * JSON_HEX_TAG/AMP/APOS/QUOT prevent closing the <script> tag or breaking
     * the context even if the payload should include strings in the future.
     */
    public function getProductsJsonForScript(): string
    {
        return json_encode(
            $this->getAssignedProducts(),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
    }

    /**
     * @return array<int, int> {productId: position} format expected by the grid serializer
     */
    private function getAssignedProducts(): array
    {
        $templateId = (int)$this->getRequest()->getParam('template_id', 0);
        $products = $this->templateResource->getAssignedProductIds($templateId);

        return $products ? array_fill_keys($products, 0) : [];
    }
}
