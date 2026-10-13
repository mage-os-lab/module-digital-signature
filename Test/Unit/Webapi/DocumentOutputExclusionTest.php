<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Webapi;

use MageOS\DigitalSignature\Api\Data\DocumentInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression test: internal fields of a signature document (callback token
 * hash, PDF storage paths) must stay out of the REST and SOAP output. The
 * exclusion is configured on DataObjectProcessor per Web API area.
 */
class DocumentOutputExclusionTest extends TestCase
{
    private const INTERNAL_GETTERS = ['getCallbackTokenHash', 'getPdfPath', 'getSignedPdfPath'];

    /**
     * @return array<string, array{0: string}>
     */
    public static function areaProvider(): array
    {
        return [
            'REST' => ['webapi_rest'],
            'SOAP' => ['webapi_soap'],
        ];
    }

    #[DataProvider('areaProvider')]
    public function testInternalGettersAreExcludedForBothTypeSpellings(string $area): void
    {
        $excluded = $this->excludedMethodsByType(dirname(__DIR__, 3) . '/etc/' . $area . '/di.xml');

        foreach ([DocumentInterface::class, '\\' . DocumentInterface::class] as $type) {
            self::assertArrayHasKey($type, $excluded, sprintf('No exclusion for "%s" in %s', $type, $area));
            foreach (self::INTERNAL_GETTERS as $getter) {
                self::assertContains($getter, $excluded[$type], sprintf('%s not excluded in %s', $getter, $area));
            }
        }
    }

    public function testExcludedGettersExistOnTheInterface(): void
    {
        foreach (self::INTERNAL_GETTERS as $getter) {
            self::assertTrue(method_exists(DocumentInterface::class, $getter), $getter . ' was renamed');
        }
    }

    /**
     * @return array<string, string[]>
     */
    private function excludedMethodsByType(string $file): array
    {
        $dom = new \DOMDocument();
        self::assertTrue($dom->load($file), 'Cannot read ' . $file);
        $xpath = new \DOMXPath($dom);
        $types = $xpath->query(
            '//type[@name="Magento\\Framework\\Reflection\\DataObjectProcessor"]'
            . '/arguments/argument[@name="excludedMethodsClassMap"]/item'
        );
        $result = [];
        foreach ($types as $typeItem) {
            /** @var \DOMElement $typeItem */
            foreach ($xpath->query('item', $typeItem) as $method) {
                $result[$typeItem->getAttribute('name')][] = trim($method->textContent);
            }
        }

        return $result;
    }
}
