<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Controller\Adminhtml\DossierExport;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Source inspection: Magento\Backend\App\Action is not available in the
 * standalone harness (same convention as MassExportDossierTest).
 */
class DossierExportControllersTest extends TestCase
{
    private function source(string $controller): string
    {
        $path = __DIR__ . '/../../../../../Controller/Adminhtml/DossierExport/' . $controller . '.php';
        self::assertFileExists($path);

        return (string)file_get_contents($path);
    }

    public static function controllers(): array
    {
        return [
            'Index' => ['Index', 'HttpGetActionInterface'],
            'Download' => ['Download', 'HttpGetActionInterface'],
            'Delete' => ['Delete', 'HttpPostActionInterface'],
            'MassDelete' => ['MassDelete', 'HttpPostActionInterface'],
        ];
    }

    #[DataProvider('controllers')]
    public function testUsesTheDossierExportAclAndTheRightHttpMethod(string $controller, string $httpInterface): void
    {
        $source = $this->source($controller);

        self::assertStringContainsString(
            "ADMIN_RESOURCE = 'MageOS_DigitalSignature::document_dossier_export'",
            $source
        );
        self::assertMatchesRegularExpression('/implements\s+' . $httpInterface . '/', $source);
    }

    public function testDownloadServesOnlyReadyExportsFromVarWithoutRemovingTheFile(): void
    {
        $source = $this->source('Download');

        self::assertStringContainsString('STATUS_READY', $source);
        self::assertStringContainsString("'rm' => false", $source);
        self::assertStringContainsString('DirectoryList::VAR_DIR', $source);
        self::assertStringNotContainsString('getParam(\'path', $source, 'the path must come from the row, never the request');
    }

    public function testMassDeletePinsItsGridNamespace(): void
    {
        self::assertStringContainsString(
            "getParam('namespace') !== 'digitalsignature_dossierexport_listing'",
            $this->source('MassDelete')
        );
    }
}
