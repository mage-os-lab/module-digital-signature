<?php

declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Controller\Adminhtml\Document;

use PHPUnit\Framework\TestCase;

/**
 * Source-inspection test rather than a reflection/instantiation-based one: the Magento
 * framework classes ExportXlsx extends (Magento\Backend\App\Action) are not available in
 * this dev harness, so the class file cannot be autoloaded here.
 */
class ExportXlsxTest extends TestCase
{
    private function getSource(): string
    {
        $path = __DIR__ . '/../../../../../Controller/Adminhtml/Document/ExportXlsx.php';
        self::assertFileExists($path);

        return (string)file_get_contents($path);
    }

    public function testControllerAcceptsPostRequests(): void
    {
        $source = $this->getSource();

        self::assertMatchesRegularExpression(
            '/class\s+ExportXlsx\s+extends\s+Action\s+implements\s+HttpPostActionInterface/',
            $source,
            'ExportXlsx must implement HttpPostActionInterface: the grid export button always '
            . 'issues a POST request, so a Get-only controller is rejected by HttpMethodValidator '
            . 'before execute() ever runs.'
        );
    }

    public function testControllerDoesNotImportHttpGetActionInterface(): void
    {
        $source = $this->getSource();

        self::assertStringNotContainsString(
            'HttpGetActionInterface',
            $source,
            'ExportXlsx must not implement HttpGetActionInterface without also accepting POST, '
            . 'otherwise HttpMethodValidator rejects the POST request sent by the grid export button.'
        );
    }
}
