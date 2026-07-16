<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Block\Adminhtml\Template;

use MageOS\DigitalSignature\Block\Adminhtml\Template\Tab\Product as ProductGrid;
use MageOS\DigitalSignature\Model\ResourceModel\Template as TemplateResource;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Wrapper griglia + serializer per l'assegnazione prodotti nel form template.
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
     * JSON sicuro per inserimento diretto in un blocco <script> (niente @noEscape):
     * JSON_HEX_TAG/AMP/APOS/QUOT impediscono di chiudere il tag <script> o rompere
     * il contesto anche se in futuro il payload dovesse includere stringhe.
     */
    public function getProductsJsonForScript(): string
    {
        return json_encode(
            $this->getAssignedProducts(),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
    }

    /**
     * @return array<int, int> formato {productId: position} atteso dal serializer della griglia
     */
    private function getAssignedProducts(): array
    {
        $templateId = (int)$this->getRequest()->getParam('template_id', 0);
        $products = $this->templateResource->getAssignedProductIds($templateId);

        return $products ? array_fill_keys($products, 0) : [];
    }
}
