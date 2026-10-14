<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Config\Source;

use MageOS\DigitalSignature\Api\Data\DossierExportInterface;
use Magento\Framework\Data\OptionSourceInterface;

class DossierExportStatus implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => DossierExportInterface::STATUS_QUEUED, 'label' => __('Queued')],
            ['value' => DossierExportInterface::STATUS_PROCESSING, 'label' => __('Processing')],
            ['value' => DossierExportInterface::STATUS_READY, 'label' => __('Ready')],
            ['value' => DossierExportInterface::STATUS_FAILED, 'label' => __('Failed')],
            ['value' => DossierExportInterface::STATUS_EXPIRED, 'label' => __('Expired')],
        ];
    }
}
