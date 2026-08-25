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
    public const CREATED_AT = 'created_at';

    /** order_item_id = 0 indicates a cart-scope document */
    public const ITEM_ID_CART_SCOPE = 0;

    /**
     * Returns document id.
     *
     * @return int|null
     */
    public function getDocumentId(): ?int;

    /**
     * Returns order id.
     *
     * @return int
     */
    public function getOrderId(): int;

    /**
     * Sets order id.
     *
     * @param int $orderId
     * @return $this
     */
    public function setOrderId(int $orderId): self;

    /**
     * Returns order item id.
     *
     * @return int
     */
    public function getOrderItemId(): int;

    /**
     * Sets order item id.
     *
     * @param int $orderItemId
     * @return $this
     */
    public function setOrderItemId(int $orderItemId): self;

    /**
     * Returns template id.
     *
     * @return int|null
     */
    public function getTemplateId(): ?int;

    /**
     * Sets template id.
     *
     * @param int|null $templateId
     * @return $this
     */
    public function setTemplateId(?int $templateId): self;

    /**
     * Returns store id.
     *
     * @return int|null
     */
    public function getStoreId(): ?int;

    /**
     * Sets store id.
     *
     * @param int|null $storeId
     * @return $this
     */
    public function setStoreId(?int $storeId): self;

    /**
     * Returns provider code.
     *
     * @return string
     */
    public function getProviderCode(): string;

    /**
     * Sets provider code.
     *
     * @param string $providerCode
     * @return $this
     */
    public function setProviderCode(string $providerCode): self;

    /**
     * Returns provider process id.
     *
     * @return string|null
     */
    public function getProviderProcessId(): ?string;

    /**
     * Sets provider process id.
     *
     * @param string|null $processId
     * @return $this
     */
    public function setProviderProcessId(?string $processId): self;

    /**
     * Returns status.
     *
     * @return string
     */
    public function getStatus(): string;

    /**
     * Sets status.
     *
     * @param string $status
     * @return $this
     */
    public function setStatus(string $status): self;

    /**
     * Returns is active.
     *
     * @return bool
     */
    public function getIsActive(): bool;

    /**
     * false = archived (the column is set to NULL to exit the unique index)
     *
     * @param bool $isActive
     * @return $this
     */
    public function setIsActive(bool $isActive): self;

    /**
     * Sha256 hash of the callback token (never the plaintext token)
     *
     * @return string|null
     */
    public function getCallbackTokenHash(): ?string;

    /**
     * Sets callback token hash.
     *
     * @param string|null $hash
     * @return $this
     */
    public function setCallbackTokenHash(?string $hash): self;

    /**
     * Returns trigger code.
     *
     * @return string
     */
    public function getTriggerCode(): string;

    /**
     * Sets trigger code.
     *
     * @param string $triggerCode
     * @return $this
     */
    public function setTriggerCode(string $triggerCode): self;

    /**
     * Returns pdf path.
     *
     * @return string|null
     */
    public function getPdfPath(): ?string;

    /**
     * Sets pdf path.
     *
     * @param string|null $pdfPath
     * @return $this
     */
    public function setPdfPath(?string $pdfPath): self;

    /**
     * Returns signed pdf path.
     *
     * @return string|null
     */
    public function getSignedPdfPath(): ?string;

    /**
     * Sets signed pdf path.
     *
     * @param string|null $signedPdfPath
     * @return $this
     */
    public function setSignedPdfPath(?string $signedPdfPath): self;

    /**
     * Returns signer email.
     *
     * @return string|null
     */
    public function getSignerEmail(): ?string;

    /**
     * Sets signer email.
     *
     * @param string|null $signerEmail
     * @return $this
     */
    public function setSignerEmail(?string $signerEmail): self;

    /**
     * Returns signer phone.
     *
     * @return string|null
     */
    public function getSignerPhone(): ?string;

    /**
     * Sets signer phone.
     *
     * @param string|null $signerPhone
     * @return $this
     */
    public function setSignerPhone(?string $signerPhone): self;

    /**
     * Returns error message.
     *
     * @return string|null
     */
    public function getErrorMessage(): ?string;

    /**
     * Sets error message.
     *
     * @param string|null $errorMessage
     * @return $this
     */
    public function setErrorMessage(?string $errorMessage): self;

    /**
     * Returns retry count.
     *
     * @return int
     */
    public function getRetryCount(): int;

    /**
     * Sets retry count.
     *
     * @param int $retryCount
     * @return $this
     */
    public function setRetryCount(int $retryCount): self;

    /**
     * Date/time at which the PDF and personal data were deleted by retention (NULL = never)
     *
     * @return string|null
     */
    public function getPurgedAt(): ?string;

    /**
     * Sets purged at.
     *
     * @param string|null $purgedAt
     * @return $this
     */
    public function setPurgedAt(?string $purgedAt): self;

    /**
     * Returns reminder count.
     *
     * @return int
     */
    public function getReminderCount(): int;

    /**
     * Sets reminder count.
     *
     * @param int $reminderCount
     * @return $this
     */
    public function setReminderCount(int $reminderCount): self;

    /**
     * Returns last reminder at.
     *
     * @return string|null
     */
    public function getLastReminderAt(): ?string;

    /**
     * Sets last reminder at.
     *
     * @param string|null $lastReminderAt
     * @return $this
     */
    public function setLastReminderAt(?string $lastReminderAt): self;

    /**
     * Returns escalation sent at.
     *
     * @return string|null
     */
    public function getEscalationSentAt(): ?string;

    /**
     * Sets escalation sent at.
     *
     * @param string|null $escalationSentAt
     * @return $this
     */
    public function setEscalationSentAt(?string $escalationSentAt): self;

    /**
     * Returns updated at.
     *
     * @return string
     */
    public function getUpdatedAt(): string;

    /**
     * Sets updated at.
     *
     * @param string $updatedAt
     * @return $this
     */
    public function setUpdatedAt(string $updatedAt): self;
}
