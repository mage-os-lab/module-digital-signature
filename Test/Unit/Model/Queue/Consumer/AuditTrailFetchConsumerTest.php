<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Queue\Consumer;

use Magento\Framework\Exception\FileSystemException;
use MageOS\DigitalSignature\Api\AuditTrailCertificateProviderInterface;
use MageOS\DigitalSignature\Api\AuditTrailRepositoryInterface;
use MageOS\DigitalSignature\Api\Data\AuditTrailInterface;
use MageOS\DigitalSignature\Api\Data\DocumentInterface;
use MageOS\DigitalSignature\Api\DocumentRepositoryInterface;
use MageOS\DigitalSignature\Api\DocumentStorageInterface;
use MageOS\DigitalSignature\Api\SignProviderInterface;
use MageOS\DigitalSignature\Api\SignProviderPoolInterface;
use MageOS\DigitalSignature\Exception\ProviderException;
use MageOS\DigitalSignature\Model\AuditTrail;
use MageOS\DigitalSignature\Model\Document\Status;
use MageOS\DigitalSignature\Model\AuditTrailFactory;
use MageOS\DigitalSignature\Model\Provider\Result\AuditTrailCertificateResult;
use MageOS\DigitalSignature\Model\Queue\Consumer\AuditTrailFetchConsumer;
use MageOS\DigitalSignature\Model\Service\AuditTrailComplianceGate;
use MageOS\DigitalSignature\TestSupport\ModelDoubles;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AuditTrailFetchConsumerTest extends TestCase
{
    use ModelDoubles;

    private DocumentRepositoryInterface&MockObject $documentRepository;
    private AuditTrailRepositoryInterface&MockObject $auditTrailRepository;
    private AuditTrailFactory&MockObject $auditTrailFactory;
    private SignProviderPoolInterface&MockObject $providerPool;
    private DocumentStorageInterface&MockObject $storage;
    private AuditTrailComplianceGate&MockObject $complianceGate;
    private LoggerInterface&MockObject $logger;
    private AuditTrailFetchConsumer $consumer;
    private AuditTrail $auditTrail;

    protected function setUp(): void
    {
        $this->documentRepository = $this->createMock(DocumentRepositoryInterface::class);
        $this->auditTrailRepository = $this->createMock(AuditTrailRepositoryInterface::class);
        $this->auditTrailFactory = $this->createMock(AuditTrailFactory::class);
        $this->providerPool = $this->createMock(SignProviderPoolInterface::class);
        $this->storage = $this->createMock(DocumentStorageInterface::class);
        $this->complianceGate = $this->createMock(AuditTrailComplianceGate::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->auditTrail = $this->newModel(AuditTrail::class);
        $this->auditTrail->setDocumentId(7);
        $this->auditTrail->setProviderCode('docusign');
        $this->auditTrailRepository->method('getByDocumentId')->with(7)->willReturn($this->auditTrail);
        $this->auditTrailFactory->method('create')->willReturn($this->newModel(AuditTrail::class));
        $this->complianceGate->method('isDataCollectionEnabled')->willReturn(true);

        $this->consumer = new AuditTrailFetchConsumer(
            $this->documentRepository,
            $this->auditTrailRepository,
            $this->auditTrailFactory,
            $this->providerPool,
            $this->storage,
            $this->complianceGate,
            $this->logger
        );
    }

    public function testMarksUnavailableWhenProviderDoesNotImplementInterface(): void
    {
        $document = $this->buildDocument();
        $this->documentRepository->method('getById')->with(7)->willReturn($document);
        $this->providerPool->method('get')->with('docusign')->willReturn($this->createMock(SignProviderInterface::class));
        $this->storage->expects(self::never())->method('write');
        $this->auditTrailRepository->expects(self::once())->method('save')->with(
            self::callback(fn (AuditTrail $a) => $a->getFetchStatus() === AuditTrailInterface::FETCH_STATUS_UNAVAILABLE)
        );

        $this->consumer->process('7');
    }

    public function testStoresCertificateAndMetadataOnSuccess(): void
    {
        $document = $this->buildDocument();
        $this->documentRepository->method('getById')->with(7)->willReturn($document);
        $provider = $this->createCertificateProvider(
            new AuditTrailCertificateResult('%PDF-cert%', '203.0.113.5', 'email', 'env-123')
        );
        $this->providerPool->method('get')->with('docusign')->willReturn($provider);
        $this->storage->expects(self::once())->method('write')
            ->with('order_9/document_7_certificate.pdf', '%PDF-cert%');
        $this->auditTrailRepository->expects(self::once())->method('save')->with(
            self::callback(function (AuditTrail $a) {
                return $a->getFetchStatus() === AuditTrailInterface::FETCH_STATUS_FETCHED
                    && $a->getCertificatePath() === 'order_9/document_7_certificate.pdf'
                    && $a->getSignerIp() === '203.0.113.5'
                    && $a->getAuthenticationMethod() === 'email'
                    && $a->getTransactionId() === 'env-123';
            })
        );

        $this->consumer->process('7');
    }

    public function testIncrementsAttemptsAndRecordsErrorOnFailure(): void
    {
        $document = $this->buildDocument();
        $this->documentRepository->method('getById')->with(7)->willReturn($document);
        $provider = $this->createCertificateProvider(null, ProviderException::retryable(__('not ready yet')));
        $this->providerPool->method('get')->with('docusign')->willReturn($provider);

        $this->auditTrailRepository->expects(self::once())->method('save')->with(
            self::callback(function (AuditTrail $a) {
                return $a->getFetchStatus() === AuditTrailInterface::FETCH_STATUS_ERROR
                    && $a->getFetchAttempts() === 1
                    && str_contains((string)$a->getLastError(), 'not ready yet');
            })
        );

        $this->consumer->process('7');
    }

    public function testPermanentFailureIsTerminalWithinTheColumnRange(): void
    {
        $document = $this->buildDocument();
        $this->documentRepository->method('getById')->with(7)->willReturn($document);
        $provider = $this->createCertificateProvider(null, ProviderException::permanent(__('envelope not found')));
        $this->providerPool->method('get')->with('docusign')->willReturn($provider);

        // fetch_attempts is SMALLINT UNSIGNED: a larger sentinel is rejected
        // by MySQL in strict mode and the failure would never become terminal.
        $this->auditTrailRepository->expects(self::once())->method('save')->with(
            self::callback(fn (AuditTrail $a) => $a->getFetchStatus() === AuditTrailInterface::FETCH_STATUS_ERROR
                && $a->getFetchAttempts() === 65535)
        );

        $this->consumer->process('7');
    }

    public function testSkipsDocumentPurgedByRetentionAfterBeingQueued(): void
    {
        $document = $this->buildDocument(purgedAt: '2026-10-01 03:00:00');
        $this->documentRepository->method('getById')->with(7)->willReturn($document);

        $this->providerPool->expects(self::never())->method('get');
        $this->storage->expects(self::never())->method('write');
        $this->auditTrailRepository->expects(self::never())->method('save');

        $this->consumer->process('7');
    }

    public function testSkipsDocumentThatIsNotSigned(): void
    {
        $document = $this->buildDocument(status: Status::CANCELED);
        $this->documentRepository->method('getById')->with(7)->willReturn($document);

        $this->providerPool->expects(self::never())->method('get');
        $this->auditTrailRepository->expects(self::never())->method('save');

        $this->consumer->process('7');
    }

    public function testDoesNothingWhenDataCollectionDisabled(): void
    {
        $document = $this->buildDocument();
        $this->documentRepository->method('getById')->with(7)->willReturn($document);
        $this->complianceGate = $this->createMock(AuditTrailComplianceGate::class);
        $this->complianceGate->method('isDataCollectionEnabled')->with(9)->willReturn(false);
        $this->consumer = new AuditTrailFetchConsumer(
            $this->documentRepository,
            $this->auditTrailRepository,
            $this->auditTrailFactory,
            $this->providerPool,
            $this->storage,
            $this->complianceGate,
            $this->logger
        );

        $this->providerPool->expects(self::never())->method('get');
        $this->storage->expects(self::never())->method('write');
        $this->auditTrailRepository->expects(self::never())->method('save');

        $this->consumer->process('7');
    }

    public function testWidensExceptionHandlingBeyondProviderExceptionOnStorageFailure(): void
    {
        $document = $this->buildDocument();
        $this->documentRepository->method('getById')->with(7)->willReturn($document);
        $provider = $this->createCertificateProvider(
            new AuditTrailCertificateResult('%PDF-cert%', '203.0.113.5', 'email', 'env-123')
        );
        $this->providerPool->method('get')->with('docusign')->willReturn($provider);
        $this->storage->method('write')->willThrowException(
            new FileSystemException(__('disk full'))
        );

        $this->auditTrailRepository->expects(self::once())->method('save')->with(
            self::callback(function (AuditTrail $a) {
                return $a->getFetchStatus() === AuditTrailInterface::FETCH_STATUS_ERROR
                    && $a->getFetchAttempts() === 1
                    && str_contains((string)$a->getLastError(), 'disk full');
            })
        );

        $this->consumer->process('7');
    }

    private function createCertificateProvider(
        ?AuditTrailCertificateResult $result,
        ?ProviderException $throw = null
    ): SignProviderInterface&AuditTrailCertificateProviderInterface {
        return new class ($result, $throw) implements SignProviderInterface, AuditTrailCertificateProviderInterface {
            public function __construct(
                private readonly ?AuditTrailCertificateResult $result,
                private readonly ?ProviderException $throw
            ) {
            }

            public function fetchCertificate(DocumentInterface $document): AuditTrailCertificateResult
            {
                if ($this->throw !== null) {
                    throw $this->throw;
                }

                return $this->result;
            }

            public function getCode(): string
            {
                return 'docusign';
            }

            public function getLabel(): string
            {
                return 'DocuSign';
            }

            public function isEnabled(?int $storeId = null): bool
            {
                return true;
            }

            public function start(DocumentInterface $document, string $pdfContent, string $callbackToken): \MageOS\DigitalSignature\Model\Provider\Result\StartResult
            {
                throw new \LogicException('not used in this test');
            }

            public function fetchStatus(DocumentInterface $document): \MageOS\DigitalSignature\Model\Provider\Result\StatusResult
            {
                throw new \LogicException('not used in this test');
            }

            public function downloadSignedPdf(DocumentInterface $document): string
            {
                throw new \LogicException('not used in this test');
            }

            public function cancel(DocumentInterface $document): void
            {
            }

            public function mapStatus(string $providerStatus): ?string
            {
                return null;
            }
        };
    }

    private function buildDocument(
        ?string $purgedAt = null,
        string $status = Status::SIGNED
    ): DocumentInterface&MockObject {
        $document = $this->createMock(DocumentInterface::class);
        $document->method('getPurgedAt')->willReturn($purgedAt);
        $document->method('getStatus')->willReturn($status);
        $document->method('getDocumentId')->willReturn(7);
        $document->method('getOrderId')->willReturn(9);
        $document->method('getProviderCode')->willReturn('docusign');
        $document->method('getStoreId')->willReturn(9);

        return $document;
    }
}
