<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Api\Data;

interface AuditTrailInterface
{
    public const DOCUMENT_ID = 'document_id';
    public const PROVIDER_CODE = 'provider_code';
    public const TRANSACTION_ID = 'transaction_id';
    public const HASH_BEFORE_SIGNING = 'hash_before_signing';
    public const HASH_AFTER_SIGNING = 'hash_after_signing';
    public const CERTIFICATE_PATH = 'certificate_path';
    public const SIGNER_IP = 'signer_ip';
    public const AUTHENTICATION_METHOD = 'authentication_method';
    public const FETCH_STATUS = 'fetch_status';
    public const FETCH_ATTEMPTS = 'fetch_attempts';
    public const LAST_ERROR = 'last_error';
    public const FETCHED_AT = 'fetched_at';
    public const CREATED_AT = 'created_at';

    public const FETCH_STATUS_PENDING = 'pending';
    public const FETCH_STATUS_FETCHED = 'fetched';
    public const FETCH_STATUS_UNAVAILABLE = 'unavailable';
    public const FETCH_STATUS_ERROR = 'error';

    public function getDocumentId(): ?int;

    public function setDocumentId(int $documentId): self;

    public function getProviderCode(): string;

    public function setProviderCode(string $providerCode): self;

    public function getTransactionId(): ?string;

    public function setTransactionId(?string $transactionId): self;

    public function getHashBeforeSigning(): ?string;

    public function setHashBeforeSigning(?string $hash): self;

    public function getHashAfterSigning(): ?string;

    public function setHashAfterSigning(?string $hash): self;

    public function getCertificatePath(): ?string;

    public function setCertificatePath(?string $certificatePath): self;

    public function getSignerIp(): ?string;

    public function setSignerIp(?string $signerIp): self;

    public function getAuthenticationMethod(): ?string;

    public function setAuthenticationMethod(?string $authenticationMethod): self;

    public function getFetchStatus(): string;

    public function setFetchStatus(string $fetchStatus): self;

    public function getFetchAttempts(): int;

    public function setFetchAttempts(int $fetchAttempts): self;

    public function getLastError(): ?string;

    public function setLastError(?string $lastError): self;

    public function getFetchedAt(): ?string;

    public function setFetchedAt(?string $fetchedAt): self;
}
