<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Api\Data;

/**
 * A queued dossier export. State columns (status, heartbeat, timestamps) are
 * written only by Model\Dossier\DossierExportStateMachine.
 */
interface DossierExportInterface
{
    public const EXPORT_ID = 'export_id';
    public const STATUS = 'status';
    public const DOCUMENT_IDS = 'document_ids';
    public const DOCUMENT_COUNT = 'document_count';
    public const PROCESSED_DOCUMENTS = 'processed_documents';
    public const REQUESTED_BY = 'requested_by';
    public const REQUESTED_BY_USER_ID = 'requested_by_user_id';
    public const FILE_PATH = 'file_path';
    public const FILE_SIZE = 'file_size';
    public const ERROR = 'error';
    public const CREATED_AT = 'created_at';
    public const STARTED_AT = 'started_at';
    public const HEARTBEAT_AT = 'heartbeat_at';
    public const COMPLETED_AT = 'completed_at';
    public const EXPIRES_AT = 'expires_at';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXPIRED = 'expired';

    public function getExportId(): ?int;

    public function getStatus(): string;

    public function setStatus(string $status): self;

    /**
     * @return int[]
     */
    public function getDocumentIds(): array;

    /**
     * Also sets document_count.
     *
     * @param array<int|string> $documentIds
     */
    public function setDocumentIds(array $documentIds): self;

    public function getDocumentCount(): int;

    public function getRequestedBy(): ?string;

    public function setRequestedBy(?string $username): self;

    public function getRequestedByUserId(): ?int;

    public function setRequestedByUserId(?int $userId): self;

    public function getFilePath(): ?string;

    public function getCompletedAt(): ?string;
}
