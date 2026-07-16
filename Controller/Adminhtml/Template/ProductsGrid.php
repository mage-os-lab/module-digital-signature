<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Controller\Adminhtml\Template;

use MageOS\DigitalSignature\Block\Adminhtml\Template\Tab\Product as ProductGridBlock;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\View\LayoutFactory;

class ProductsGrid extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_DigitalSignature::template';

    public function __construct(
        Action\Context $context,
        private readonly RawFactory $resultRawFactory,
        private readonly LayoutFactory $layoutFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): Raw
    {
        $grid = $this->layoutFactory->create()->createBlock(
            ProductGridBlock::class,
            'digitalsignature.template.products.grid'
        );

        return $this->resultRawFactory->create()->setContents($grid->toHtml());
    }
}
