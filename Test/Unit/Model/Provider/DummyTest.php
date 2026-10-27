<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Provider;

use MageOS\DigitalSignature\Api\Data\DocumentInterface;
use MageOS\DigitalSignature\Api\DocumentStorageInterface;
use MageOS\DigitalSignature\Exception\ProviderException;
use MageOS\DigitalSignature\Model\Document\Status;
use MageOS\DigitalSignature\Model\Pdf\SignatureStamp;
use MageOS\DigitalSignature\Model\Provider\Dummy;
use MageOS\DigitalSignature\Model\Provider\ProviderConfig;
use Magento\Framework\Math\Random;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DummyTest extends TestCase
{
    private ProviderConfig&MockObject $config;
    private DocumentStorageInterface&MockObject $storage;
    private DateTime&MockObject $dateTime;
    private SignatureStamp&MockObject $signatureStamp;
    private Dummy $provider;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ProviderConfig::class);
        $this->storage = $this->createMock(DocumentStorageInterface::class);
        $this->dateTime = $this->createMock(DateTime::class);
        $this->signatureStamp = $this->createMock(SignatureStamp::class);
        $this->provider = new Dummy(
            $this->config,
            $this->storage,
            $this->createMock(Random::class),
            $this->dateTime,
            $this->signatureStamp
        );
    }

    public function testCodeAndLabel(): void
    {
        self::assertSame('dummy', $this->provider->getCode());
        self::assertSame('Dummy (test only)', $this->provider->getLabel());
    }

    public function testIsEnabledReflectsTheAllowFlag(): void
    {
        $this->config->method('isSetFlag')->with('dummy', 'allow', 5)->willReturn(true);

        self::assertTrue($this->provider->isEnabled(5));
    }

    public function testDownloadSignedPdfStampsTheGeneratedPdf(): void
    {
        $this->config->method('isSetFlag')->willReturn(true);

        $document = $this->createMock(DocumentInterface::class);
        $document->method('getStoreId')->willReturn(1);
        $document->method('getPdfPath')->willReturn('order_1/document_1.pdf');

        $this->storage->method('exists')->with('order_1/document_1.pdf')->willReturn(true);
        $this->storage->method('read')->with('order_1/document_1.pdf')->willReturn('%PDF-generated');

        $this->dateTime->method('gmtTimestamp')->willReturn(gmmktime(12, 1, 0, 1, 1, 2026));

        $this->signatureStamp->expects(self::once())
            ->method('stampAt')
            ->with('%PDF-generated', 'DUMMY SIGNED at 12:01:00')
            ->willReturn('%PDF-stamped');

        self::assertSame('%PDF-stamped', $this->provider->downloadSignedPdf($document));
    }

    public function testDownloadSignedPdfRejectsWhenProviderNotAllowed(): void
    {
        $this->config->method('isSetFlag')->willReturn(false);
        $document = $this->createMock(DocumentInterface::class);

        $this->expectException(ProviderException::class);

        $this->provider->downloadSignedPdf($document);
    }

    public function testDownloadSignedPdfRejectsMissingPdf(): void
    {
        $this->config->method('isSetFlag')->willReturn(true);
        $document = $this->createMock(DocumentInterface::class);
        $document->method('getPdfPath')->willReturn(null);

        $this->expectException(ProviderException::class);

        $this->provider->downloadSignedPdf($document);
    }

    public function testMapStatus(): void
    {
        self::assertSame(Status::SIGNED, $this->provider->mapStatus(Dummy::RAW_SIGNED));
        self::assertSame(Status::SENT, $this->provider->mapStatus(Dummy::RAW_IN_PROGRESS));
        self::assertNull($this->provider->mapStatus('ANYTHING_ELSE'));
    }
}
