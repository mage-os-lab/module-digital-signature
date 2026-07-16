<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Connector;

use MageOS\DigitalSignature\Model\Connector\AvailableConnector;
use MageOS\DigitalSignature\Model\Connector\AvailableConnectorsCatalog;
use PHPUnit\Framework\TestCase;

class AvailableConnectorsCatalogTest extends TestCase
{
    private AvailableConnectorsCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new AvailableConnectorsCatalog();
    }

    public function testGetAllReturnsExpectedConnectors(): void
    {
        $connectors = $this->catalog->getAll();

        self::assertCount(1, $connectors);
        self::assertInstanceOf(AvailableConnector::class, $connectors[0]);

        $adobeSign = $connectors[0];
        self::assertSame('adobe_sign', $adobeSign->getCode());
        self::assertSame('Adobe Acrobat Sign', $adobeSign->getName());
        self::assertSame('in_development', $adobeSign->getStatus());
        self::assertNotEmpty($adobeSign->getDescription());
        self::assertSame('https://www.adobe.com/sign.html', $adobeSign->getLink());
    }
}
