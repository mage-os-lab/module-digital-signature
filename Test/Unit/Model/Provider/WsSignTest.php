<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Provider;

use MageOS\DigitalSignature\Model\Document\Status;
use MageOS\DigitalSignature\Model\Provider\ProviderConfig;
use MageOS\DigitalSignature\Model\Provider\WsSign;
use MageOS\DigitalSignature\Model\Provider\WsSign\Client;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Url\Validator as UrlValidator;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class WsSignTest extends TestCase
{
    private ProviderConfig&MockObject $config;
    private WsSign $provider;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ProviderConfig::class);
        $this->provider = new WsSign(
            $this->createMock(Client::class),
            $this->config,
            $this->createMock(StoreManagerInterface::class),
            new Json(),
            $this->createMock(UrlValidator::class)
        );
    }

    public function testCodeAndLabel(): void
    {
        self::assertSame('wssign', $this->provider->getCode());
        self::assertSame('WsSign', $this->provider->getLabel());
    }

    public function testMapStatusUsesConfiguredMappingCaseInsensitive(): void
    {
        $this->config->method('getSerialized')->willReturn([
            ['provider_status' => 'COMPLETED', 'internal_status' => Status::SIGNED],
        ]);

        self::assertSame(Status::SIGNED, $this->provider->mapStatus('completed'));
        self::assertSame(Status::SIGNED, $this->provider->mapStatus('COMPLETED'));
    }

    public function testMapStatusRejectsUnknownInternalStatus(): void
    {
        $this->config->method('getSerialized')->willReturn([
            ['provider_status' => 'X', 'internal_status' => 'stato-inventato'],
        ]);

        self::assertNull($this->provider->mapStatus('X'));
    }

    public function testMapStatusReturnsNullForUnmappedStatus(): void
    {
        $this->config->method('getSerialized')->willReturn([]);

        self::assertNull($this->provider->mapStatus('STATO_NUOVO'));
    }

    public function testMapStatusSkipsMalformedRows(): void
    {
        $this->config->method('getSerialized')->willReturn([
            'garbage',
            ['provider_status' => 'OK', 'internal_status' => Status::SIGNED],
        ]);

        self::assertSame(Status::SIGNED, $this->provider->mapStatus('OK'));
    }

    public function testMapStatusNotFoundFallsBackToExpired(): void
    {
        $this->config->method('getSerialized')->willReturn([]);

        self::assertSame(Status::EXPIRED, $this->provider->mapStatus(WsSign::RAW_STATUS_NOT_FOUND));
    }

    public function testMapStatusConfiguredMappingWinsOverNotFoundFallback(): void
    {
        $this->config->method('getSerialized')->willReturn([
            ['provider_status' => WsSign::RAW_STATUS_NOT_FOUND, 'internal_status' => Status::CANCELED],
        ]);

        self::assertSame(Status::CANCELED, $this->provider->mapStatus(WsSign::RAW_STATUS_NOT_FOUND));
    }
}
