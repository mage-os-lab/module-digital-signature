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
        return $this->jsonSerializer->serialize($this->getAssignedProductsAsObject());
    }

    /**
     * Safe JSON for direct insertion into a <script> block (no @noEscape):
     * JSON_HEX_TAG/AMP/APOS/QUOT prevent closing the <script> tag or breaking
     * the context even if the payload should include strings in the future.
     */
    public function getProductsJsonForScript(): string
    {
        return json_encode(
            $this->getAssignedProductsAsObject(),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
    }

    /**
     * A PHP empty array (and, once a template has only sequential 0-based keys,
     * even a non-empty one) encodes as a JSON array "[]" rather than an object
     * "{}". The client-side selectedProducts would then start as a JS Array,
     * and assigning numeric-string product ids onto it (selectedProducts["5"]
     * = 0) sets array indexes instead of object keys: JSON.stringify emits
     * holes as null (e.g. "[null,null,null,null,null,0]"), and the server's
     * array_keys() on decode reads back its own 0..5 indexes as if they were
     * product ids, instead of the real entity_id that was selected. Casting
     * to stdClass forces json_encode to always emit an object, so the keys
     * stay the real product ids on both sides.
     *
     * @return \stdClass|array<int, int>
     */
    private function getAssignedProductsAsObject(): \stdClass|array
    {
        $products = $this->getAssignedProducts();

        return $products ?: new \stdClass();
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
