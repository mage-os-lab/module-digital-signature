<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Dossier;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Dossier export limits (default scope only) and the reject/sync/async
 * decision for a selection of a given size.
 */
class DossierExportConfig
{
    public const MODE_REJECT = 'reject';
    public const MODE_SYNC = 'sync';
    public const MODE_ASYNC = 'async';

    private const XML_PATH_MAX_DOCUMENTS = 'digital_signature/dossier_export/max_documents';
    private const XML_PATH_SYNC_THRESHOLD = 'digital_signature/dossier_export/sync_threshold';
    private const XML_PATH_RETENTION_HOURS = 'digital_signature/dossier_export/retention_hours';

    public function __construct(private readonly ScopeConfigInterface $scopeConfig)
    {
    }

    public function getMaxDocuments(): int
    {
        return max(1, (int)$this->scopeConfig->getValue(self::XML_PATH_MAX_DOCUMENTS));
    }

    public function getSyncThreshold(): int
    {
        return max(0, (int)$this->scopeConfig->getValue(self::XML_PATH_SYNC_THRESHOLD));
    }

    public function getRetentionHours(): int
    {
        return max(1, (int)$this->scopeConfig->getValue(self::XML_PATH_RETENTION_HOURS));
    }

    public function resolveMode(int $documentCount): string
    {
        if ($documentCount > $this->getMaxDocuments()) {
            return self::MODE_REJECT;
        }

        return $documentCount <= $this->getSyncThreshold() ? self::MODE_SYNC : self::MODE_ASYNC;
    }
}
