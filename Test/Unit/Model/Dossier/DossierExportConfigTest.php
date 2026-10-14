<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Dossier;

use MageOS\DigitalSignature\Model\Dossier\DossierExportConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

class DossierExportConfigTest extends TestCase
{
    private function config(string $max, string $threshold, string $hours = '72'): DossierExportConfig
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            ['digital_signature/dossier_export/max_documents', 'default', null, $max],
            ['digital_signature/dossier_export/sync_threshold', 'default', null, $threshold],
            ['digital_signature/dossier_export/retention_hours', 'default', null, $hours],
        ]);

        return new DossierExportConfig($scopeConfig);
    }

    public function testSelectionUpToTheThresholdIsSynchronous(): void
    {
        self::assertSame(DossierExportConfig::MODE_SYNC, $this->config('500', '20')->resolveMode(20));
    }

    public function testSelectionAboveTheThresholdIsQueued(): void
    {
        self::assertSame(DossierExportConfig::MODE_ASYNC, $this->config('500', '20')->resolveMode(21));
    }

    public function testSelectionOfExactlyTheMaximumIsAllowed(): void
    {
        self::assertSame(DossierExportConfig::MODE_ASYNC, $this->config('500', '20')->resolveMode(500));
    }

    public function testSelectionAboveTheMaximumIsRejected(): void
    {
        self::assertSame(DossierExportConfig::MODE_REJECT, $this->config('500', '20')->resolveMode(501));
    }

    public function testThresholdZeroQueuesEverySelection(): void
    {
        self::assertSame(DossierExportConfig::MODE_ASYNC, $this->config('500', '0')->resolveMode(1));
    }

    public function testThresholdAboveMaximumMakesEveryAllowedExportSynchronous(): void
    {
        $config = $this->config('10', '50');

        self::assertSame(DossierExportConfig::MODE_SYNC, $config->resolveMode(10));
        self::assertSame(DossierExportConfig::MODE_REJECT, $config->resolveMode(11));
    }

    public function testInvalidValuesFallBackToSafeMinimums(): void
    {
        $config = $this->config('0', '-5', '0');

        self::assertSame(1, $config->getMaxDocuments());
        self::assertSame(0, $config->getSyncThreshold());
        self::assertSame(1, $config->getRetentionHours());
    }
}
