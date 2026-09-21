<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Provider;

use MageOS\DigitalSignature\Model\Document\Status;
use MageOS\DigitalSignature\Model\Provider\ProviderConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ProviderConfigTest extends TestCase
{
    private ScopeConfigInterface&MockObject $scopeConfig;
    private ProviderConfig $config;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->config = new ProviderConfig(
            $this->scopeConfig,
            $this->createMock(EncryptorInterface::class),
            new Json()
        );
    }

    private function stubStatusMapping(array $rows): void
    {
        $this->scopeConfig->method('getValue')->willReturn(json_encode($rows));
    }

    public function testMapConfiguredStatusMatchesCaseInsensitively(): void
    {
        $this->stubStatusMapping([
            ['provider_status' => 'COMPLETED', 'internal_status' => Status::SIGNED],
        ]);

        self::assertSame(Status::SIGNED, $this->config->mapConfiguredStatus('docusign', 'completed'));
    }

    public function testMapConfiguredStatusReturnsNullWhenNoRowMatches(): void
    {
        $this->stubStatusMapping([
            ['provider_status' => 'COMPLETED', 'internal_status' => Status::SIGNED],
        ]);

        self::assertNull($this->config->mapConfiguredStatus('docusign', 'something-else'));
    }

    public function testMapConfiguredStatusRejectsUnknownInternalStatus(): void
    {
        $this->stubStatusMapping([
            ['provider_status' => 'X', 'internal_status' => 'stato-inventato'],
        ]);

        self::assertNull($this->config->mapConfiguredStatus('docusign', 'X'));
    }

    public function testMapConfiguredStatusSkipsMalformedRows(): void
    {
        $this->stubStatusMapping([
            'garbage',
            ['provider_status' => 'OK', 'internal_status' => Status::SIGNED],
        ]);

        self::assertSame(Status::SIGNED, $this->config->mapConfiguredStatus('docusign', 'OK'));
    }

    public function testMapConfiguredStatusReturnsNullWhenNothingConfigured(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);

        self::assertNull($this->config->mapConfiguredStatus('docusign', 'anything'));
    }
}
