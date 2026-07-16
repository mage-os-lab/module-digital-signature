<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Api\Data;

interface DocumentInterface
{
    public const DOCUMENT_ID = 'document_id';
    public const ORDER_ID = 'order_id';
    public const ORDER_ITEM_ID = 'order_item_id';
    public const TEMPLATE_ID = 'template_id';
    public const STORE_ID = 'store_id';
    public const PROVIDER_CODE = 'provider_code';
    public const PROVIDER_PROCESS_ID = 'provider_process_id';
    public const STATUS = 'status';
    public const IS_ACTIVE = 'is_active';
    public const CALLBACK_TOKEN = 'callback_token';
    public const TRIGGER_CODE = 'trigger_code';
    public const PDF_PATH = 'pdf_path';
    public const SIGNED_PDF_PATH = 'signed_pdf_path';
    public const SIGNER_EMAIL = 'signer_email';
    public const SIGNER_PHONE = 'signer_phone';
    public const ERROR_MESSAGE = 'error_message';
    public const RETRY_COUNT = 'retry_count';
    public const PURGED_AT = 'purged_at';
    public const REMINDER_COUNT = 'reminder_count';
    public const LAST_REMINDER_AT = 'last_reminder_at';
    public const ESCALATION_SENT_AT = 'escalation_sent_at';

    /** order_item_id = 0 indica documento di scope carrello */
    public const ITEM_ID_CART_SCOPE = 0;

    public function getDocumentId(): ?int;

    public function getOrderId(): int;

    public function setOrderId(int $orderId): self;

    public function getOrderItemId(): int;

    public function setOrderItemId(int $orderItemId): self;

    public function getTemplateId(): ?int;

    public function setTemplateId(?int $templateId): self;

    public function getStoreId(): ?int;

    public function setStoreId(?int $storeId): self;

    public function getProviderCode(): string;

    public function setProviderCode(string $providerCode): self;

    public function getProviderProcessId(): ?string;

    public function setProviderProcessId(?string $processId): self;

    public function getStatus(): string;

    public function setStatus(string $status): self;

    public function getIsActive(): bool;

    /** false = storicizzato (la colonna passa a NULL per uscire dall'indice univoco) */
    public function setIsActive(bool $isActive): self;

    /** Hash sha256 del token di callback (mai il token in chiaro) */
    public function getCallbackTokenHash(): ?string;

    public function setCallbackTokenHash(?string $hash): self;

    public function getTriggerCode(): string;

    public function setTriggerCode(string $triggerCode): self;

    public function getPdfPath(): ?string;

    public function setPdfPath(?string $pdfPath): self;

    public function getSignedPdfPath(): ?string;

    public function setSignedPdfPath(?string $signedPdfPath): self;

    public function getSignerEmail(): ?string;

    public function setSignerEmail(?string $signerEmail): self;

    public function getSignerPhone(): ?string;

    public function setSignerPhone(?string $signerPhone): self;

    public function getErrorMessage(): ?string;

    public function setErrorMessage(?string $errorMessage): self;

    public function getRetryCount(): int;

    public function setRetryCount(int $retryCount): self;

    /** Data/ora in cui PDF e dati personali sono stati eliminati dalla retention (NULL = mai) */
    public function getPurgedAt(): ?string;

    public function setPurgedAt(?string $purgedAt): self;

    public function getReminderCount(): int;

    public function setReminderCount(int $reminderCount): self;

    public function getLastReminderAt(): ?string;

    public function setLastReminderAt(?string $lastReminderAt): self;

    public function getEscalationSentAt(): ?string;

    public function setEscalationSentAt(?string $escalationSentAt): self;

    public function getUpdatedAt(): string;

    public function setUpdatedAt(string $updatedAt): self;
}
