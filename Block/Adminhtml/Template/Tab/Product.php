<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Block\Adminhtml\Template\Tab;

use MageOS\DigitalSignature\Model\ResourceModel\Template as TemplateResource;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Grid\Column;
use Magento\Backend\Block\Widget\Grid\Extended;
use Magento\Backend\Helper\Data as BackendHelper;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;

/**
 * Griglia prodotti assegnabili al template (stile "Prodotti in categoria").
 */
class Product extends Extended
{
    public function __construct(
        Context $context,
        BackendHelper $backendHelper,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly TemplateResource $templateResource,
        array $data = []
    ) {
        parent::__construct($context, $backendHelper, $data);
    }

    protected function _construct(): void
    {
        parent::_construct();
        $this->setId('digitalsignature_template_products_grid');
        $this->setDefaultSort('entity_id');
        $this->setDefaultDir('ASC');
        $this->setUseAjax(true);
        $this->setSaveParametersInSession(false);
        if ($this->getTemplateId()) {
            $this->setDefaultFilter(['in_products' => 1]);
        }
    }

    public function getTemplateId(): int
    {
        return (int)$this->getRequest()->getParam('template_id', 0);
    }

    protected function _prepareCollection(): static
    {
        $collection = $this->productCollectionFactory->create()
            ->addAttributeToSelect(['name', 'sku', 'price']);
        $this->setCollection($collection);

        return parent::_prepareCollection();
    }

    protected function _addColumnFilterToCollection($column): static
    {
        if ($column->getId() === 'in_products') {
            $productIds = $this->_getSelectedProducts();
            if (empty($productIds)) {
                $productIds = 0;
            }
            if ($column->getFilter()->getValue()) {
                $this->getCollection()->addFieldToFilter('entity_id', ['in' => $productIds]);
            } elseif ($productIds) {
                $this->getCollection()->addFieldToFilter('entity_id', ['nin' => $productIds]);
            }

            return $this;
        }

        return parent::_addColumnFilterToCollection($column);
    }

    protected function _prepareColumns(): static
    {
        $this->addColumn('in_products', [
            'type' => 'checkbox',
            'name' => 'in_products',
            'values' => $this->_getSelectedProducts(),
            'index' => 'entity_id',
            'header_css_class' => 'col-select col-massaction',
            'column_css_class' => 'col-select col-massaction',
        ]);
        $this->addColumn('entity_id', [
            'header' => __('ID'),
            'sortable' => true,
            'index' => 'entity_id',
            'header_css_class' => 'col-id',
            'column_css_class' => 'col-id',
        ]);
        $this->addColumn('name', [
            'header' => __('Nome'),
            'index' => 'name',
        ]);
        $this->addColumn('sku', [
            'header' => __('SKU'),
            'index' => 'sku',
        ]);
        $this->addColumn('price', [
            'header' => __('Prezzo'),
            'type' => 'currency',
            'currency_code' => (string)$this->_scopeConfig->getValue(
                \Magento\Directory\Model\Currency::XML_PATH_CURRENCY_BASE,
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE
            ),
            'index' => 'price',
        ]);

        return parent::_prepareColumns();
    }

    public function getGridUrl(): string
    {
        return $this->getUrl('digitalsignature/template/productsGrid', [
            'template_id' => $this->getTemplateId(),
            '_current' => true,
        ]);
    }

    /**
     * @return int[]
     */
    protected function _getSelectedProducts(): array
    {
        $products = $this->getRequest()->getPost('selected_products');
        if ($products !== null) {
            return array_map('intval', (array)$products);
        }

        return $this->templateResource->getAssignedProductIds($this->getTemplateId());
    }
}
