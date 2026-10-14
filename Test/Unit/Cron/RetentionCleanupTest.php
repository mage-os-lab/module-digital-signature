<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Cron;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use MageOS\DigitalSignature\Api\AuditTrailRepositoryInterface;
use MageOS\DigitalSignature\Api\DocumentRepositoryInterface;
use MageOS\DigitalSignature\Api\DocumentStorageInterface;
use MageOS\DigitalSignature\Cron\RetentionCleanup;
use MageOS\DigitalSignature\Model\AuditTrail;
use MageOS\DigitalSignature\Model\ResourceModel\AuditTrail\Collection as AuditTrailCollection;
use MageOS\DigitalSignature\Model\ResourceModel\AuditTrail\CollectionFactory as AuditTrailCollectionFactory;
use MageOS\DigitalSignature\Model\ResourceModel\Document\Collection as DocumentCollection;
use MageOS\DigitalSignature\Model\ResourceModel\Document\CollectionFactory;
use MageOS\DigitalSignature\TestSupport\ModelDoubles;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RetentionCleanupTest extends TestCase
{
    use ModelDoubles;

    private CollectionFactory&MockObject $collectionFactory;
    private DocumentRepositoryInterface&MockObject $documentRepository;
    private DocumentStorageInterface&MockObject $storage;
    private ScopeConfigInterface&MockObject $scopeConfig;
    private DateTime&MockObject $dateTime;
    private LoggerInterface&MockObject $logger;
    private AuditTrailCollectionFactory&MockObject $auditTrailCollectionFactory;
    private AuditTrailRepositoryInterface&MockObject $auditTrailRepository;
    private RetentionCleanup $retentionCleanup;

    protected function setUp(): void
    {
        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $this->documentRepository = $this->createMock(DocumentRepositoryInterface::class);
        $this->storage = $this->createMock(DocumentStorageInterface::class);
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->dateTime = $this->createMock(DateTime::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->auditTrailCollectionFactory = $this->createMock(AuditTrailCollectionFactory::class);
        $this->auditTrailRepository = $this->createMock(AuditTrailRepositoryInterface::class);

        $this->dateTime->method('gmtTimestamp')->willReturn(1_700_000_000);
        $this->collectionFactory->method('create')->willReturn($this->newCollection(DocumentCollection::class));
        

        $this->retentionCleanup = new RetentionCleanup(
            $this->collectionFactory,
            $this->documentRepository,
            $this->storage,
            $this->scopeConfig,
            $this->dateTime,
            $this->logger,
            $this->auditTrailCollectionFactory,
            $this->auditTrailRepository
        );
    }

    private function configureConfig(bool $enabled, int $retentionDays, int $batchLimit, int $auditTrailRetentionDays): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturn($enabled);
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['digital_signature/retention/retention_days', 'default', null, (string)$retentionDays],
            ['digital_signature/retention/batch_limit', 'default', null, (string)$batchLimit],
            ['digital_signature/audit_trail/retention_days', 'default', null, (string)$auditTrailRetentionDays],
        ]);
    }

    private function auditTrailWith(string $certificatePath, ?string $signerIp = '203.0.113.5'): AuditTrail
    {
        $auditTrail = $this->newModel(AuditTrail::class);
        $auditTrail->setDocumentId(42);
        $auditTrail->setProviderCode('docusign');
        $auditTrail->setCertificatePath($certificatePath);
        $auditTrail->setSignerIp($signerIp);
        $auditTrail->setAuthenticationMethod('email');
        $auditTrail->setHashBeforeSigning('hash-before');
        $auditTrail->setHashAfterSigning('hash-after');
        $auditTrail->setTransactionId('env-1');

        return $auditTrail;
    }

    public function testPurgesCertificateAndSignerDataButKeepsProofData(): void
    {
        $this->configureConfig(true, 3650, 100, 30);

        $auditTrail = $this->auditTrailWith('order_1/document_42_certificate.pdf');
        $auditTrailCollection = $this->newCollection(AuditTrailCollection::class, [$auditTrail]);
        $this->auditTrailCollectionFactory->method('create')->willReturn($auditTrailCollection);

        $this->storage->expects(self::once())->method('delete')
            ->with('order_1/document_42_certificate.pdf');
        $this->auditTrailRepository->expects(self::once())->method('save')->with(
            self::callback(function (AuditTrail $a) {
                return $a->getCertificatePath() === null
                    && $a->getSignerIp() === null
                    && $a->getAuthenticationMethod() === null
                    && $a->getHashBeforeSigning() === 'hash-before'
                    && $a->getHashAfterSigning() === 'hash-after'
                    && $a->getTransactionId() === 'env-1';
            })
        );

        $this->retentionCleanup->execute();
    }

    public function testKeepsCertificatePathWhenTheFileCannotBeDeletedSoThePurgeIsRetried(): void
    {
        $this->configureConfig(true, 3650, 100, 30);

        $auditTrail = $this->auditTrailWith('order_1/document_42_certificate.pdf');
        $this->auditTrailCollectionFactory->method('create')
            ->willReturn($this->newCollection(AuditTrailCollection::class, [$auditTrail]));
        $this->storage->method('delete')->willThrowException(new FileSystemException(__('permission denied')));

        $this->auditTrailRepository->expects(self::once())->method('save')->with(
            self::callback(fn (AuditTrail $a) => $a->getCertificatePath() === 'order_1/document_42_certificate.pdf'
                && $a->getSignerIp() === null
                && $a->getAuthenticationMethod() === null)
        );

        $this->retentionCleanup->execute();
    }

    public function testSkipsAuditTrailPurgeWhenRetentionDaysNotConfigured(): void
    {
        $this->configureConfig(true, 3650, 100, 0);

        $this->auditTrailCollectionFactory->expects(self::never())->method('create');
        $this->auditTrailRepository->expects(self::never())->method('save');

        $this->retentionCleanup->execute();
    }

    public function testPurgesAuditTrailOnItsOwnScheduleEvenWhenDocumentRetentionIsDisabled(): void
    {
        // The audit trail retention is set explicitly when the merchant opts in to
        // collection: it must be honoured even if the (destructive, opt-in)
        // document retention is off, otherwise signer IPs and certificates
        // would be kept forever.
        $this->configureConfig(false, 3650, 100, 30);

        $auditTrailCollection = $this->newCollection(
            AuditTrailCollection::class,
            [$this->auditTrailWith('order_1/document_42_certificate.pdf')]
        );
        $this->auditTrailCollectionFactory->expects(self::once())->method('create')
            ->willReturn($auditTrailCollection);
        $this->collectionFactory->expects(self::never())->method('create');
        $this->storage->expects(self::once())->method('delete')
            ->with('order_1/document_42_certificate.pdf');
        $this->auditTrailRepository->expects(self::once())->method('save');

        $this->retentionCleanup->execute();
    }
}
