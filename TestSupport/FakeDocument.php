<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\TestSupport;

use MageOS\DigitalSignature\Api\Data\DocumentInterface;

/**
 * Implementazione in-memory di DocumentInterface per i test unit.
 */
class FakeDocument implements DocumentInterface
{
    public ?int $documentId = 55;

    /** @var array<string, mixed> */
    private array $data = [];

    public function getDocumentId(): ?int
    {
        return $this->documentId;
    }

    public function getOrderId(): int
    {
        return (int)($this->data[self::ORDER_ID] ?? 0);
    }

    public function setOrderId(int $orderId): DocumentInterface
    {
        $this->data[self::ORDER_ID] = $orderId;

        return $this;
    }

    public function getOrderItemId(): int
    {
        return (int)($this->data[self::ORDER_ITEM_ID] ?? 0);
    }

    public function setOrderItemId(int $orderItemId): DocumentInterface
    {
        $this->data[self::ORDER_ITEM_ID] = $orderItemId;

        return $this;
    }

    public function getTemplateId(): ?int
    {
        return $this->data[self::TEMPLATE_ID] ?? null;
    }

    public function setTemplateId(?int $templateId): DocumentInterface
    {
        $this->data[self::TEMPLATE_ID] = $templateId;

        return $this;
    }

    public function getStoreId(): ?int
    {
        return $this->data[self::STORE_ID] ?? null;
    }

    public function setStoreId(?int $storeId): DocumentInterface
    {
        $this->data[self::STORE_ID] = $storeId;

        return $this;
    }

    public function getProviderCode(): string
    {
        return (string)($this->data[self::PROVIDER_CODE] ?? '');
    }

    public function setProviderCode(string $providerCode): DocumentInterface
    {
        $this->data[self::PROVIDER_CODE] = $providerCode;

        return $this;
    }

    public function getProviderProcessId(): ?string
    {
        return $this->data[self::PROVIDER_PROCESS_ID] ?? null;
    }

    public function setProviderProcessId(?string $processId): DocumentInterface
    {
        $this->data[self::PROVIDER_PROCESS_ID] = $processId;

        return $this;
    }

    public function getStatus(): string
    {
        return (string)($this->data[self::STATUS] ?? 'pending');
    }

    public function setStatus(string $status): DocumentInterface
    {
        $this->data[self::STATUS] = $status;

        return $this;
    }

    public function getIsActive(): bool
    {
        return (bool)($this->data[self::IS_ACTIVE] ?? true);
    }

    public function setIsActive(bool $isActive): DocumentInterface
    {
        $this->data[self::IS_ACTIVE] = $isActive;

        return $this;
    }

    public function getCallbackTokenHash(): ?string
    {
        return $this->data[self::CALLBACK_TOKEN] ?? null;
    }

    public function setCallbackTokenHash(?string $hash): DocumentInterface
    {
        $this->data[self::CALLBACK_TOKEN] = $hash;

        return $this;
    }

    public function getTriggerCode(): string
    {
        return (string)($this->data[self::TRIGGER_CODE] ?? '');
    }

    public function setTriggerCode(string $triggerCode): DocumentInterface
    {
        $this->data[self::TRIGGER_CODE] = $triggerCode;

        return $this;
    }

    public function getPdfPath(): ?string
    {
        return $this->data[self::PDF_PATH] ?? null;
    }

    public function setPdfPath(?string $pdfPath): DocumentInterface
    {
        $this->data[self::PDF_PATH] = $pdfPath;

        return $this;
    }

    public function getSignedPdfPath(): ?string
    {
        return $this->data[self::SIGNED_PDF_PATH] ?? null;
    }

    public function setSignedPdfPath(?string $signedPdfPath): DocumentInterface
    {
        $this->data[self::SIGNED_PDF_PATH] = $signedPdfPath;

        return $this;
    }

    public function getSignerEmail(): ?string
    {
        return $this->data[self::SIGNER_EMAIL] ?? null;
    }

    public function setSignerEmail(?string $signerEmail): DocumentInterface
    {
        $this->data[self::SIGNER_EMAIL] = $signerEmail;

        return $this;
    }

    public function getSignerPhone(): ?string
    {
        return $this->data[self::SIGNER_PHONE] ?? null;
    }

    public function setSignerPhone(?string $signerPhone): DocumentInterface
    {
        $this->data[self::SIGNER_PHONE] = $signerPhone;

        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->data[self::ERROR_MESSAGE] ?? null;
    }

    public function setErrorMessage(?string $errorMessage): DocumentInterface
    {
        $this->data[self::ERROR_MESSAGE] = $errorMessage;

        return $this;
    }

    public function getRetryCount(): int
    {
        return (int)($this->data[self::RETRY_COUNT] ?? 0);
    }

    public function setRetryCount(int $retryCount): DocumentInterface
    {
        $this->data[self::RETRY_COUNT] = $retryCount;

        return $this;
    }

    public function getPurgedAt(): ?string
    {
        return $this->data[self::PURGED_AT] ?? null;
    }

    public function setPurgedAt(?string $purgedAt): DocumentInterface
    {
        $this->data[self::PURGED_AT] = $purgedAt;

        return $this;
    }

    public function getReminderCount(): int
    {
        return (int)($this->data[self::REMINDER_COUNT] ?? 0);
    }

    public function setReminderCount(int $reminderCount): DocumentInterface
    {
        $this->data[self::REMINDER_COUNT] = $reminderCount;

        return $this;
    }

    public function getLastReminderAt(): ?string
    {
        return $this->data[self::LAST_REMINDER_AT] ?? null;
    }

    public function setLastReminderAt(?string $lastReminderAt): DocumentInterface
    {
        $this->data[self::LAST_REMINDER_AT] = $lastReminderAt;

        return $this;
    }

    public function getEscalationSentAt(): ?string
    {
        return $this->data[self::ESCALATION_SENT_AT] ?? null;
    }

    public function setEscalationSentAt(?string $escalationSentAt): DocumentInterface
    {
        $this->data[self::ESCALATION_SENT_AT] = $escalationSentAt;

        return $this;
    }

    public function getUpdatedAt(): string
    {
        return (string)($this->data['updated_at'] ?? '');
    }

    public function setUpdatedAt(string $updatedAt): DocumentInterface
    {
        $this->data['updated_at'] = $updatedAt;

        return $this;
    }
}
