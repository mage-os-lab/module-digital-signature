<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\ResourceModel\DossierExport;

use MageOS\DigitalSignature\Model\DossierExport;
use MageOS\DigitalSignature\Model\ResourceModel\DossierExport as DossierExportResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'export_id';
    protected $_eventPrefix = 'digitalsignature_dossier_export_collection';

    protected function _construct(): void
    {
        $this->_init(DossierExport::class, DossierExportResource::class);
    }
}
