<?php

declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Controller\Adminhtml\Document;

use PHPUnit\Framework\TestCase;

/**
 * Source-inspection test rather than a reflection/instantiation-based one: the Magento
 * framework classes MassExportDossier extends (Magento\Backend\App\Action) are not
 * available in this dev harness, so the class file cannot be autoloaded here. Same
 * approach as ExportXlsxTest.
 */
class MassExportDossierTest extends TestCase
{
    private function getSource(): string
    {
        $path = __DIR__ . '/../../../../../Controller/Adminhtml/Document/MassExportDossier.php';
        self::assertFileExists($path);

        return (string)file_get_contents($path);
    }

    public function testControllerAcceptsPostRequests(): void
    {
        $source = $this->getSource();

        self::assertMatchesRegularExpression(
            '/class\s+MassExportDossier\s+extends\s+Action\s+implements\s+HttpPostActionInterface/',
            $source,
            'MassExportDossier must implement HttpPostActionInterface: mass action requests are '
            . 'always POST, so a Get-only controller is rejected by HttpMethodValidator before '
            . 'execute() ever runs.'
        );
    }

    public function testControllerPinsItsOwnGridNamespace(): void
    {
        $source = $this->getSource();

        self::assertStringContainsString(
            "getParam('namespace') !== 'digitalsignature_document_listing'",
            $source,
            'MassExportDossier must pin the request namespace to its own grid: '
            . 'Filter::getCollection() resolves the UI component via the attacker-controlled '
            . '"namespace" request param, same risk documented on ExportXlsx::_isAllowed().'
        );
    }

    public function testAdminResourceIsTheDedicatedDossierExportAcl(): void
    {
        $source = $this->getSource();

        self::assertStringContainsString(
            "ADMIN_RESOURCE = 'MageOS_DigitalSignature::document_dossier_export'",
            $source,
            'MassExportDossier must use its own ACL resource, not the plain "document" resource, '
            . 'since it exposes certificate content alongside the PDF.'
        );
    }

    public function testShowsAMessageWhenNoDocumentsAreSelected(): void
    {
        $source = $this->getSource();

        self::assertStringContainsString(
            'No documents were selected.',
            $source,
            'MassExportDossier must tell the admin when the mass action was submitted with an '
            . 'empty selection, instead of generating an empty/meaningless dossier.'
        );
    }

    public function testSynchronousExportIsStreamedFromAFileNotBufferedInMemory(): void
    {
        $source = $this->getSource();

        self::assertStringContainsString('buildToFile(', $source);
        self::assertStringContainsString("'rm' => true", $source, 'the temporary ZIP must be removed after streaming');
        self::assertStringContainsString('application/zip', $source);
        self::assertStringNotContainsString('RawFactory', $source, 'no in-memory Raw response any more');
        self::assertStringNotContainsString('file_get_contents', $source);
    }

    public function testSelectionSizeIsDecidedByTheConfiguredPolicy(): void
    {
        $source = $this->getSource();

        self::assertStringContainsString('resolveMode(', $source);
        self::assertStringContainsString('DossierExportConfig::MODE_REJECT', $source);
        self::assertStringContainsString('DossierExportConfig::MODE_ASYNC', $source);
        self::assertStringContainsString(
            'You selected %1 documents: the dossier export is limited to %2. Narrow the selection.',
            $source
        );
    }

    public function testLargeSelectionsAreQueuedWithTheRequestingAdmin(): void
    {
        $source = $this->getSource();

        self::assertStringContainsString('->schedule(', $source);
        self::assertStringContainsString(
            'Dossier export #%1 queued (%2 documents). You will be notified when it is ready under Digital Signature > Dossier exports.',
            $source
        );
    }

    public function testCatchesThrowableNotJustException(): void
    {
        $source = $this->getSource();

        self::assertStringContainsString(
            'catch (\Throwable $e)',
            $source,
            'MassExportDossier must catch \Throwable (not just \Exception), matching the '
            . 'ExportXlsx::execute() convention, so an \Error subtype (e.g. a ValueError from '
            . 'a misused ZipArchive) still gets the friendly redirect+message instead of a raw '
            . 'Magento error page.'
        );
    }

    public function testDoesNotLeakTheRawExceptionMessageToTheAdminUi(): void
    {
        $source = $this->getSource();

        self::assertStringNotContainsString(
            "Unable to generate the dossier: %1",
            $source,
            'The admin-facing error message must not interpolate the raw exception message; '
            . 'log it server-side instead, matching ExportXlsx::execute().'
        );
        self::assertStringContainsString(
            'An error occurred while generating the dossier.',
            $source,
            'MassExportDossier must show a generic, non-leaking error message to the admin.'
        );
    }

    public function testPassesTheAdminUsernameToDossierBuilder(): void
    {
        $source = $this->getSource();

        self::assertMatchesRegularExpression(
            '/dossierBuilder->buildToFile\(\s*\$documentIds\s*,[^;]*\$adminUsername\s*\)/s',
            $source,
            'MassExportDossier must pass the current admin username to DossierBuilder::buildToFile() '
            . 'so the manifest\'s generated_by provenance field is populated.'
        );
    }

    public function testStreamsThroughFileFactoryAsAnAttachmentFromVar(): void
    {
        $source = $this->getSource();

        // FileFactory always sends Content-Disposition: attachment and its own
        // Cache-Control (it overwrites any header set before it), the same as
        // the existing XLSX export; the file is removed right after ('rm').
        self::assertStringContainsString('$this->fileFactory->create(', $source);
        self::assertStringContainsString('DirectoryList::VAR_DIR', $source);
    }
}
