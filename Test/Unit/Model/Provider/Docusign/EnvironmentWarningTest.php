<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Provider\Docusign;

use MageOS\DigitalSignature\Model\Provider\Docusign;
use MageOS\DigitalSignature\Model\Provider\Docusign\EnvironmentWarning;
use MageOS\DigitalSignature\Model\Provider\ProviderConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class EnvironmentWarningTest extends TestCase
{
    private ProviderConfig&MockObject $config;
    private EnvironmentWarning $environmentWarning;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ProviderConfig::class);
        $this->environmentWarning = new EnvironmentWarning($this->config);
    }

    public function testReturnsNullWhenEnvironmentIsProduction(): void
    {
        $this->config->method('get')->with(Docusign::CODE, 'environment')->willReturn('production');

        self::assertNull($this->environmentWarning->getMessage());
    }

    public function testReturnsWarningWhenEnvironmentIsDemo(): void
    {
        $this->config->method('get')->with(Docusign::CODE, 'environment')->willReturn('demo');

        self::assertNotNull($this->environmentWarning->getMessage());
    }

    public function testReturnsWarningWhenEnvironmentIsNotConfigured(): void
    {
        $this->config->method('get')->with(Docusign::CODE, 'environment')->willReturn(null);

        self::assertNotNull($this->environmentWarning->getMessage());
    }
}
