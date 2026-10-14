<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Service;

use MageOS\DigitalSignature\Api\AuditTrailRepositoryInterface;
use MageOS\DigitalSignature\Api\Data\AuditTrailInterface;
use MageOS\DigitalSignature\Api\Data\DocumentInterface;
use MageOS\DigitalSignature\Api\DocumentRepositoryInterface;
use MageOS\DigitalSignature\Api\DocumentStorageInterface;
use MageOS\DigitalSignature\Exception\DossierExportAbortedException;
use MageOS\DigitalSignature\Model\Service\DossierBuilder;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ZipArchive;

class DossierBuilderTest extends TestCase
{
    private DocumentRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject $documentRepository;
    private DocumentStorageInterface&\PHPUnit\Framework\MockObject\MockObject $storage;
    private AuditTrailRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject $auditTrailRepository;
    private LoggerInterface&\PHPUnit\Framework\MockObject\MockObject $logger;
    private DossierBuilder $builder;

    protected function setUp(): void
    {
        $this->documentRepository = $this->createMock(DocumentRepositoryInterface::class);
        $this->storage = $this->createMock(DocumentStorageInterface::class);
        $this->auditTrailRepository = $this->createMock(AuditTrailRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->builder = new DossierBuilder(
            $this->documentRepository,
            $this->storage,
            $this->auditTrailRepository,
            $this->logger
        );
    }

    private function makeDocument(
        int $id,
        int $orderId = 5,
        string $status = 'signed',
        string $providerCode = 'docusign',
        ?string $signedPdfPath = 'signed/1.pdf',
        ?string $pdfPath = 'generated/1.pdf',
        ?string $purgedAt = null
    ): DocumentInterface&\PHPUnit\Framework\MockObject\MockObject {
        $document = $this->createMock(DocumentInterface::class);
        $document->method('getDocumentId')->willReturn($id);
        $document->method('getOrderId')->willReturn($orderId);
        $document->method('getStatus')->willReturn($status);
        $document->method('getProviderCode')->willReturn($providerCode);
        $document->method('getSignedPdfPath')->willReturn($signedPdfPath);
        $document->method('getPdfPath')->willReturn($pdfPath);
        $document->method('getPurgedAt')->willReturn($purgedAt);

        return $document;
    }

    private function makeAuditTrail(
        string $fetchStatus,
        ?string $certificatePath,
        ?string $hashBefore = 'hash-before',
        ?string $hashAfter = 'hash-after',
        ?string $transactionId = 'txn-1'
    ): AuditTrailInterface&\PHPUnit\Framework\MockObject\MockObject {
        $auditTrail = $this->createMock(AuditTrailInterface::class);
        $auditTrail->method('getFetchStatus')->willReturn($fetchStatus);
        $auditTrail->method('getCertificatePath')->willReturn($certificatePath);
        $auditTrail->method('getHashBeforeSigning')->willReturn($hashBefore);
        $auditTrail->method('getHashAfterSigning')->willReturn($hashAfter);
        $auditTrail->method('getTransactionId')->willReturn($transactionId);

        return $auditTrail;
    }

    /** @return array{manifest: array, files: array<string, string>} */
    private function build(array $documentIds, ?string $generatedBy = null, ?callable $onProgress = null): array
    {
        $path = $this->targetPath();
        $this->builder->buildToFile($documentIds, $path, $generatedBy, $onProgress);

        $zip = new ZipArchive();
        self::assertTrue($zip->open($path) === true, 'DossierBuilder must write a valid ZIP file');
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $files[$name] = $zip->getFromName($name);
        }
        $zip->close();
        unlink($path);

        $manifest = json_decode($files['manifest.json'], true, 512, JSON_THROW_ON_ERROR);

        return ['manifest' => $manifest, 'files' => $files];
    }

    private function targetPath(): string
    {
        return sys_get_temp_dir() . '/dossier_test_' . bin2hex(random_bytes(6)) . '.zip';
    }

    public function testBuildsZipWithPdfAndCertificateWhenBothAvailable(): void
    {
        $document = $this->makeDocument(1);
        $this->documentRepository->method('getById')->with(1)->willReturn($document);

        $auditTrail = $this->makeAuditTrail(AuditTrailInterface::FETCH_STATUS_FETCHED, 'certificates/1.pdf');
        $this->auditTrailRepository->method('getByDocumentId')->with(1)->willReturn($auditTrail);

        $this->storage->method('exists')->willReturnMap([
            ['signed/1.pdf', true],
            ['certificates/1.pdf', true],
        ]);
        $this->storage->method('read')->willReturnMap([
            ['signed/1.pdf', '%PDF-signed-content'],
            ['certificates/1.pdf', '%PDF-certificate-content'],
        ]);

        $result = $this->build([1]);

        self::assertSame('%PDF-signed-content', $result['files']['document-1/signed.pdf']);
        self::assertSame('%PDF-certificate-content', $result['files']['document-1/certificate.pdf']);

        $entry = $result['manifest']['documents'][0];
        self::assertSame(1, $entry['document_id']);
        self::assertSame(5, $entry['order_id']);
        self::assertSame('signed', $entry['status']);
        self::assertSame('docusign', $entry['provider_code']);
        self::assertSame('hash-before', $entry['hash_before_signing']);
        self::assertSame('hash-after', $entry['hash_after_signing']);
        self::assertSame('txn-1', $entry['transaction_id']);
        self::assertTrue($entry['pdf_included']);
        self::assertTrue($entry['certificate_included']);
        self::assertNull($entry['pdf_unavailable_reason']);
        self::assertNull($entry['certificate_unavailable_reason']);
    }

    public function testAnnotatesNoAuditTrailRowAsAuditTrailDisabled(): void
    {
        $document = $this->makeDocument(2);
        $this->documentRepository->method('getById')->with(2)->willReturn($document);
        $this->auditTrailRepository->method('getByDocumentId')->with(2)->willReturn(null);
        $this->storage->method('exists')->willReturn(true);
        $this->storage->method('read')->willReturn('%PDF-content');

        $result = $this->build([2]);

        $entry = $result['manifest']['documents'][0];
        self::assertFalse($entry['certificate_included']);
        self::assertSame('audit_trail_disabled', $entry['certificate_unavailable_reason']);
        self::assertArrayNotHasKey('document-2/certificate.pdf', $result['files']);
    }

    public function testAnnotatesUnavailableFetchStatusAsProviderNotSupported(): void
    {
        $document = $this->makeDocument(3);
        $this->documentRepository->method('getById')->with(3)->willReturn($document);
        $auditTrail = $this->makeAuditTrail(AuditTrailInterface::FETCH_STATUS_UNAVAILABLE, null);
        $this->auditTrailRepository->method('getByDocumentId')->with(3)->willReturn($auditTrail);
        $this->storage->method('exists')->willReturn(true);
        $this->storage->method('read')->willReturn('%PDF-content');

        $result = $this->build([3]);

        self::assertSame(
            'provider_not_supported',
            $result['manifest']['documents'][0]['certificate_unavailable_reason']
        );
    }

    public function testAnnotatesErrorFetchStatusAsFetchError(): void
    {
        $document = $this->makeDocument(4);
        $this->documentRepository->method('getById')->with(4)->willReturn($document);
        $auditTrail = $this->makeAuditTrail(AuditTrailInterface::FETCH_STATUS_ERROR, null);
        $this->auditTrailRepository->method('getByDocumentId')->with(4)->willReturn($auditTrail);
        $this->storage->method('exists')->willReturn(true);
        $this->storage->method('read')->willReturn('%PDF-content');

        $result = $this->build([4]);

        self::assertSame('fetch_error', $result['manifest']['documents'][0]['certificate_unavailable_reason']);
    }

    public function testAnnotatesPendingFetchStatusAsFetchPending(): void
    {
        $document = $this->makeDocument(5);
        $this->documentRepository->method('getById')->with(5)->willReturn($document);
        $auditTrail = $this->makeAuditTrail(AuditTrailInterface::FETCH_STATUS_PENDING, null);
        $this->auditTrailRepository->method('getByDocumentId')->with(5)->willReturn($auditTrail);
        $this->storage->method('exists')->willReturn(true);
        $this->storage->method('read')->willReturn('%PDF-content');

        $result = $this->build([5]);

        self::assertSame('fetch_pending', $result['manifest']['documents'][0]['certificate_unavailable_reason']);
    }

    public function testAnnotatesCertificateRemovedByRetentionAsPurged(): void
    {
        // RetentionCleanup clears certificate_path but keeps fetch_status =
        // fetched: the certificate existed and was deleted, it is not pending.
        $document = $this->makeDocument(5);
        $this->documentRepository->method('getById')->with(5)->willReturn($document);
        $auditTrail = $this->makeAuditTrail(AuditTrailInterface::FETCH_STATUS_FETCHED, null);
        $this->auditTrailRepository->method('getByDocumentId')->with(5)->willReturn($auditTrail);
        $this->storage->method('exists')->willReturn(true);
        $this->storage->method('read')->willReturn('%PDF-content');

        $result = $this->build([5]);

        self::assertSame('purged_by_retention', $result['manifest']['documents'][0]['certificate_unavailable_reason']);
    }

    public function testAnnotatesCertificateStorageFailureWithoutFailingTheBatch(): void
    {
        $document = $this->makeDocument(6);
        $this->documentRepository->method('getById')->with(6)->willReturn($document);
        $auditTrail = $this->makeAuditTrail(AuditTrailInterface::FETCH_STATUS_FETCHED, 'certificates/6.pdf');
        $this->auditTrailRepository->method('getByDocumentId')->with(6)->willReturn($auditTrail);

        $this->storage->method('exists')->willReturnMap([
            ['signed/1.pdf', true],
            ['certificates/6.pdf', false],
        ]);
        $this->storage->method('read')->willReturn('%PDF-content');

        $result = $this->build([6]);

        $entry = $result['manifest']['documents'][0];
        self::assertTrue($entry['pdf_included']);
        self::assertFalse($entry['certificate_included']);
        self::assertSame('storage_read_failed', $entry['certificate_unavailable_reason']);
        self::assertNull($entry['pdf_unavailable_reason']);
    }

    public function testDocumentNotFoundGetsAnErrorEntryAndDoesNotBreakTheBatch(): void
    {
        $this->documentRepository->method('getById')->willReturnCallback(
            function (int $id) {
                if ($id === 8) {
                    throw new NoSuchEntityException(__('Document not found.'));
                }

                return $this->makeDocument($id);
            }
        );
        $this->auditTrailRepository->method('getByDocumentId')->willReturn(null);
        $this->storage->method('exists')->willReturn(true);
        $this->storage->method('read')->willReturn('%PDF-content');

        $result = $this->build([7, 8]);

        self::assertCount(2, $result['manifest']['documents']);
        $notFound = $result['manifest']['documents'][1];
        self::assertSame(8, $notFound['document_id']);
        self::assertNull($notFound['order_id']);
        self::assertNull($notFound['status']);
        self::assertNull($notFound['provider_code']);
        self::assertNull($notFound['hash_before_signing']);
        self::assertNull($notFound['hash_after_signing']);
        self::assertNull($notFound['transaction_id']);
        self::assertFalse($notFound['pdf_included']);
        self::assertFalse($notFound['certificate_included']);
        self::assertSame('document_not_found', $notFound['pdf_unavailable_reason']);
        self::assertSame('document_not_found', $notFound['certificate_unavailable_reason']);
        self::assertArrayNotHasKey('document-8/document.pdf', $result['files']);
        // document 7 must still be present and unaffected
        self::assertArrayHasKey('document-7/signed.pdf', $result['files']);
    }

    public function testDocumentResolutionFailureFromGetByIdIsAnnotatedAndDoesNotBreakTheBatch(): void
    {
        $this->documentRepository->method('getById')->willReturnCallback(
            function (int $id) {
                if ($id === 20) {
                    throw new \RuntimeException('DB connection lost');
                }

                return $this->makeDocument($id);
            }
        );
        $this->auditTrailRepository->method('getByDocumentId')->willReturn(null);
        $this->storage->method('exists')->willReturn(true);
        $this->storage->method('read')->willReturn('%PDF-content');
        $this->logger->expects(self::atLeastOnce())->method('warning');

        $result = $this->build([19, 20]);

        self::assertCount(2, $result['manifest']['documents']);
        $failed = $result['manifest']['documents'][1];
        self::assertSame(20, $failed['document_id']);
        self::assertSame('resolution_failed', $failed['pdf_unavailable_reason']);
        self::assertSame('resolution_failed', $failed['certificate_unavailable_reason']);
        self::assertArrayHasKey('document-19/signed.pdf', $result['files']);
    }

    public function testAuditTrailLookupFailureIsAnnotatedAndDoesNotBreakTheBatch(): void
    {
        $document = $this->makeDocument(21);
        $this->documentRepository->method('getById')->with(21)->willReturn($document);
        $this->auditTrailRepository->method('getByDocumentId')->with(21)->willThrowException(
            new \RuntimeException('audit trail lookup exploded')
        );
        $this->storage->method('exists')->willReturn(true);
        $this->storage->method('read')->willReturn('%PDF-content');
        $this->logger->expects(self::atLeastOnce())->method('warning');

        $result = $this->build([21]);

        $entry = $result['manifest']['documents'][0];
        self::assertTrue($entry['pdf_included']);
        self::assertFalse($entry['certificate_included']);
        self::assertSame('resolution_failed', $entry['certificate_unavailable_reason']);
    }

    public function testMissingPdfPathIsAnnotatedAsNotGenerated(): void
    {
        $document = $this->makeDocument(9, signedPdfPath: null, pdfPath: null);
        $this->documentRepository->method('getById')->with(9)->willReturn($document);
        $this->auditTrailRepository->method('getByDocumentId')->with(9)->willReturn(null);

        $result = $this->build([9]);

        $entry = $result['manifest']['documents'][0];
        self::assertFalse($entry['pdf_included']);
        self::assertSame('not_generated', $entry['pdf_unavailable_reason']);
    }

    public function testAnnotatesPdfRemovedByRetentionAsPurged(): void
    {
        $document = $this->makeDocument(9, signedPdfPath: null, pdfPath: null, purgedAt: '2026-10-01 00:00:00');
        $this->documentRepository->method('getById')->with(9)->willReturn($document);
        $this->auditTrailRepository->method('getByDocumentId')->with(9)->willReturn(null);

        $result = $this->build([9]);

        $entry = $result['manifest']['documents'][0];
        self::assertFalse($entry['pdf_included']);
        self::assertSame('purged_by_retention', $entry['pdf_unavailable_reason']);
    }

    public function testUnreadablePdfIsAnnotatedAsStorageReadFailedWithoutBreakingTheBatch(): void
    {
        $document = $this->makeDocument(10);
        $this->documentRepository->method('getById')->with(10)->willReturn($document);
        $this->auditTrailRepository->method('getByDocumentId')->with(10)->willReturn(null);
        $this->storage->method('exists')->with('signed/1.pdf')->willReturn(true);
        $this->storage->method('read')->with('signed/1.pdf')->willThrowException(
            new FileSystemException(__('disk error'))
        );

        $result = $this->build([10]);

        $entry = $result['manifest']['documents'][0];
        self::assertFalse($entry['pdf_included']);
        self::assertSame('storage_read_failed', $entry['pdf_unavailable_reason']);
    }

    public function testTargetFileIsRemovedWhenJsonEncodeFails(): void
    {
        $document = $this->createMock(DocumentInterface::class);
        $document->method('getDocumentId')->willReturn(11);
        $document->method('getOrderId')->willReturn(5);
        $document->method('getStatus')->willReturn('signed');
        // Invalid UTF-8 makes json_encode() of the manifest throw
        $document->method('getProviderCode')->willReturn("\xB1\x31");
        $document->method('getSignedPdfPath')->willReturn(null);
        $document->method('getPdfPath')->willReturn('generated/1.pdf');
        $this->documentRepository->method('getById')->with(11)->willReturn($document);
        $this->auditTrailRepository->method('getByDocumentId')->with(11)->willReturn(null);
        $this->storage->method('exists')->willReturn(true);
        $this->storage->method('read')->willReturn('%PDF-content');

        $path = $this->targetPath();
        try {
            $this->builder->buildToFile([11], $path);
            self::fail('JsonException expected');
        } catch (\JsonException) {
        }

        self::assertFileDoesNotExist($path);
    }

    public function testManifestIncludesGeneratedAtAndGeneratedByWhenProvided(): void
    {
        $document = $this->makeDocument(12);
        $this->documentRepository->method('getById')->with(12)->willReturn($document);
        $this->auditTrailRepository->method('getByDocumentId')->with(12)->willReturn(null);
        $this->storage->method('exists')->willReturn(true);
        $this->storage->method('read')->willReturn('%PDF-content');

        $before = new \DateTime();
        $result = $this->build([12], 'admin_jane');
        $after = new \DateTime();

        self::assertSame('admin_jane', $result['manifest']['generated_by']);
        self::assertArrayHasKey('generated_at', $result['manifest']);
        $generatedAt = \DateTime::createFromFormat(\DateTime::ATOM, $result['manifest']['generated_at']);
        self::assertNotFalse($generatedAt, 'generated_at must be a valid ISO-8601 (ATOM) timestamp');
        self::assertGreaterThanOrEqual($before->getTimestamp() - 1, $generatedAt->getTimestamp());
        self::assertLessThanOrEqual($after->getTimestamp() + 1, $generatedAt->getTimestamp());
    }

    public function testManifestGeneratedByIsNullWhenNotProvided(): void
    {
        $document = $this->makeDocument(13);
        $this->documentRepository->method('getById')->with(13)->willReturn($document);
        $this->auditTrailRepository->method('getByDocumentId')->with(13)->willReturn(null);
        $this->storage->method('exists')->willReturn(true);
        $this->storage->method('read')->willReturn('%PDF-content');

        $result = $this->build([13]);

        self::assertArrayHasKey('generated_by', $result['manifest']);
        self::assertNull($result['manifest']['generated_by']);
    }

    public function testFlushesEveryTwentyDocumentsWithoutLosingEntries(): void
    {
        $ids = range(1, 45);
        $this->documentRepository->method('getById')->willReturnCallback(
            fn (int $id) => $this->makeDocument($id, signedPdfPath: 'signed/' . $id . '.pdf')
        );
        $this->auditTrailRepository->method('getByDocumentId')->willReturn(null);
        $this->storage->method('exists')->willReturn(true);
        $this->storage->method('read')->willReturnCallback(fn (string $path) => '%PDF-' . $path);

        $result = $this->build($ids);

        self::assertCount(45, $result['manifest']['documents']);
        self::assertSame('%PDF-signed/1.pdf', $result['files']['document-1/signed.pdf']);
        self::assertSame('%PDF-signed/21.pdf', $result['files']['document-21/signed.pdf']);
        self::assertSame('%PDF-signed/45.pdf', $result['files']['document-45/signed.pdf']);
    }

    public function testFlushSurvivesABatchWithNoFiles(): void
    {
        // The first 20 documents add nothing to the archive: closing an empty
        // ZipArchive writes no file, so the reopen must still create it.
        $this->documentRepository->method('getById')->willReturnCallback(
            function (int $id) {
                if ($id <= 20) {
                    throw new NoSuchEntityException(__('missing'));
                }

                return $this->makeDocument($id);
            }
        );
        $this->auditTrailRepository->method('getByDocumentId')->willReturn(null);
        $this->storage->method('exists')->willReturn(true);
        $this->storage->method('read')->willReturn('%PDF-content');

        $result = $this->build(range(1, 21));

        self::assertCount(21, $result['manifest']['documents']);
        self::assertSame('document_not_found', $result['manifest']['documents'][0]['pdf_unavailable_reason']);
        self::assertArrayHasKey('document-21/signed.pdf', $result['files']);
    }

    public function testReportsProgressAfterEachDocument(): void
    {
        $this->documentRepository->method('getById')->willReturnCallback(fn (int $id) => $this->makeDocument($id));
        $this->auditTrailRepository->method('getByDocumentId')->willReturn(null);
        $this->storage->method('exists')->willReturn(true);
        $this->storage->method('read')->willReturn('%PDF-content');

        $progress = [];
        $this->build([4, 5, 6], null, function (int $processed) use (&$progress) {
            $progress[] = $processed;
        });

        self::assertSame([1, 2, 3], $progress);
    }

    public function testAbortFromProgressCallbackStopsAndRemovesTheFile(): void
    {
        $this->documentRepository->method('getById')->willReturnCallback(fn (int $id) => $this->makeDocument($id));
        $this->auditTrailRepository->method('getByDocumentId')->willReturn(null);
        $this->storage->method('exists')->willReturn(true);
        $this->storage->method('read')->willReturn('%PDF-content');

        $path = $this->targetPath();
        $this->expectException(DossierExportAbortedException::class);
        try {
            $this->builder->buildToFile([1, 2, 3], $path, null, function (int $processed) {
                if ($processed === 2) {
                    throw new DossierExportAbortedException('job gone');
                }
            });
        } finally {
            self::assertFileDoesNotExist($path);
        }
    }
}
