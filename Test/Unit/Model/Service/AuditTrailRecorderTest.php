<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Service;

use MageOS\DigitalSignature\Api\AuditTrailRepositoryInterface;
use MageOS\DigitalSignature\Api\Data\AuditTrailInterface;
use MageOS\DigitalSignature\Api\Data\DocumentInterface;
use MageOS\DigitalSignature\Model\AuditTrail;
use MageOS\DigitalSignature\Model\AuditTrailFactory;
use MageOS\DigitalSignature\Model\Service\AuditTrailComplianceGate;
use MageOS\DigitalSignature\Model\Service\AuditTrailRecorder;
use MageOS\DigitalSignature\TestSupport\ModelDoubles;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AuditTrailRecorderTest extends TestCase
{
    use ModelDoubles;

    private AuditTrailComplianceGate&MockObject $gate;
    private AuditTrailRepositoryInterface&MockObject $repository;
    private AuditTrailFactory&MockObject $factory;
    private LoggerInterface&MockObject $logger;
    private AuditTrailRecorder $recorder;

    protected function setUp(): void
    {
        $this->gate = $this->createMock(AuditTrailComplianceGate::class);
        $this->repository = $this->createMock(AuditTrailRepositoryInterface::class);
        $this->factory = $this->createMock(AuditTrailFactory::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->recorder = new AuditTrailRecorder($this->gate, $this->repository, $this->factory, $this->logger);
    }

    public function testDoesNothingWhenDataCollectionDisabled(): void
    {
        $this->gate->method('isDataCollectionEnabled')->willReturn(false);
        $this->repository->expects(self::never())->method('getByDocumentId');
        $this->repository->expects(self::never())->method('save');

        $this->recorder->recordHashBeforeSigning($this->buildDocument(), 'pdf-bytes');
    }

    public function testCreatesNewRowWithHashBeforeSigningWhenNoneExists(): void
    {
        $this->gate->method('isDataCollectionEnabled')->willReturn(true);
        $this->repository->method('getByDocumentId')->with(42)->willReturn(null);
        $auditTrail = $this->newModel(AuditTrail::class);
        $this->factory->method('create')->willReturn($auditTrail);
        $this->repository->expects(self::once())->method('save')->with($auditTrail)->willReturnArgument(0);

        $this->recorder->recordHashBeforeSigning($this->buildDocument(), 'pdf-bytes');

        self::assertSame(42, $auditTrail->getDocumentId());
        self::assertSame('wssign', $auditTrail->getProviderCode());
        self::assertSame(hash('sha256', 'pdf-bytes'), $auditTrail->getHashBeforeSigning());
        self::assertSame(AuditTrailInterface::FETCH_STATUS_PENDING, $auditTrail->getFetchStatus());
    }

    public function testUpdatesExistingRowWithHashAfterSigning(): void
    {
        $this->gate->method('isDataCollectionEnabled')->willReturn(true);
        $existing = $this->newModel(AuditTrail::class);
        $existing->setDocumentId(42);
        $existing->setProviderCode('wssign');
        $existing->setHashBeforeSigning('before-hash');
        $this->repository->method('getByDocumentId')->with(42)->willReturn($existing);
        $this->factory->expects(self::never())->method('create');
        $this->repository->expects(self::once())->method('save')->with($existing)->willReturnArgument(0);

        $this->recorder->recordHashAfterSigning($this->buildDocument(), 'signed-pdf-bytes');

        self::assertSame('before-hash', $existing->getHashBeforeSigning(), 'must not overwrite the other hash');
        self::assertSame(hash('sha256', 'signed-pdf-bytes'), $existing->getHashAfterSigning());
    }

    public function testRepositoryFailureIsLoggedAndNotPropagated(): void
    {
        $this->gate->method('isDataCollectionEnabled')->willReturn(true);
        $this->repository->method('getByDocumentId')->willThrowException(new \RuntimeException('db down'));
        $this->logger->expects(self::once())->method('warning');

        $this->recorder->recordHashBeforeSigning($this->buildDocument(), 'pdf-bytes');
    }

    private function buildDocument(): DocumentInterface&MockObject
    {
        $document = $this->createMock(DocumentInterface::class);
        $document->method('getDocumentId')->willReturn(42);
        $document->method('getStoreId')->willReturn(1);
        $document->method('getProviderCode')->willReturn('wssign');

        return $document;
    }
}
