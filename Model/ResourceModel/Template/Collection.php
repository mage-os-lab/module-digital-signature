<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\ResourceModel\Template;

use MageOS\DigitalSignature\Model\ResourceModel\Template as TemplateResource;
use MageOS\DigitalSignature\Model\Template;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'template_id';
    protected $_eventPrefix = 'digitalsignature_template_collection';

    protected function _construct(): void
    {
        $this->_init(Template::class, TemplateResource::class);
    }
}
