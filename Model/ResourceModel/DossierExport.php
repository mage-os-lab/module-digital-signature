<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class DossierExport extends AbstractDb
{
    public const MAIN_TABLE = 'mageos_digitalsignature_dossier_export';

    protected function _construct(): void
    {
        $this->_init(self::MAIN_TABLE, 'export_id');
    }
}
