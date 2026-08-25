<?php

declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class DocumentListingSensitiveColumnsTest extends TestCase
{
    private const FORBIDDEN_COLUMNS = ['pdf_path', 'signed_pdf_path', 'callback_token'];

    public function testSensitiveFieldsAreNeverDeclaredAsGridColumns(): void
    {
        $path = __DIR__ . '/../../../view/adminhtml/ui_component/digitalsignature_document_listing.xml';
        self::assertFileExists($path);

        $xml = new \DOMDocument();
        $xml->load($path);

        $columnNames = [];
        foreach ($xml->getElementsByTagName('column') as $column) {
            $columnNames[] = $column->getAttribute('name');
        }
        foreach ($xml->getElementsByTagName('actionsColumn') as $column) {
            $columnNames[] = $column->getAttribute('name');
        }

        foreach (self::FORBIDDEN_COLUMNS as $forbidden) {
            self::assertNotContains(
                $forbidden,
                $columnNames,
                sprintf(
                    '"%s" must never be a Document grid column — it would leak into CSV/XML/XLSX export.',
                    $forbidden
                )
            );
        }
    }

    /**
     * Without a selectionsColumn, Magento_Ui/js/grid/export's "selections" knockout module
     * cannot resolve, the export button sends a malformed AJAX request, and the core
     * mui/export/gridToCsv and mui/export/gridToXml controllers reject it as an invalid request.
     */
    public function testListingDeclaresASelectionsColumnForCoreGridExportToWork(): void
    {
        $path = __DIR__ . '/../../../view/adminhtml/ui_component/digitalsignature_document_listing.xml';
        self::assertFileExists($path);

        $xml = new \DOMDocument();
        $xml->load($path);

        self::assertGreaterThan(
            0,
            $xml->getElementsByTagName('selectionsColumn')->length,
            'digitalsignature_document_listing.xml must declare a <selectionsColumn>, otherwise the '
            . 'core CSV/XML grid export ("mui/export/gridToCsv"/"gridToXml") fails request validation.'
        );
    }
}
