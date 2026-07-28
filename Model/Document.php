<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model;

use MageOS\DigitalSignature\Api\Data\DocumentInterface;
use MageOS\DigitalSignature\Model\ResourceModel\Document as DocumentResource;
use Magento\Framework\Model\AbstractModel;

class Document extends AbstractModel implements DocumentInterface
{
    protected $_eventPrefix = 'digitalsignature_document';

    protected function _construct(): void
    {
        $this->_init(DocumentResource::class);
    }

    public function getDocumentId(): ?int
    {
        $id = $this->getData(self::DOCUMENT_ID);
        return $id === null ? null : (int)$id;
    }

    public function getOrderId(): int
    {
        return (int)$this->getData(self::ORDER_ID);
    }

    public function setOrderId(int $orderId): DocumentInterface
    {
        return $this->setData(self::ORDER_ID, $orderId);
    }

    public function getOrderItemId(): int
    {
        return (int)$this->getData(self::ORDER_ITEM_ID);
    }

    public function setOrderItemId(int $orderItemId): DocumentInterface
    {
        return $this->setData(self::ORDER_ITEM_ID, $orderItemId);
    }

    public function getTemplateId(): ?int
    {
        $id = $this->getData(self::TEMPLATE_ID);
        return $id === null ? null : (int)$id;
    }

    public function setTemplateId(?int $templateId): DocumentInterface
    {
        return $this->setData(self::TEMPLATE_ID, $templateId);
    }

    public function getStoreId(): ?int
    {
        $id = $this->getData(self::STORE_ID);
        return $id === null ? null : (int)$id;
    }

    public function setStoreId(?int $storeId): DocumentInterface
    {
        return $this->setData(self::STORE_ID, $storeId);
    }

    public function getProviderCode(): string
    {
        return (string)$this->getData(self::PROVIDER_CODE);
    }

    public function setProviderCode(string $providerCode): DocumentInterface
    {
        return $this->setData(self::PROVIDER_CODE, $providerCode);
    }

    public function getProviderProcessId(): ?string
    {
        $value = $this->getData(self::PROVIDER_PROCESS_ID);
        return $value === null || $value === '' ? null : (string)$value;
    }

    public function setProviderProcessId(?string $processId): DocumentInterface
    {
        return $this->setData(self::PROVIDER_PROCESS_ID, $processId);
    }

    public function getStatus(): string
    {
        return (string)$this->getData(self::STATUS);
    }

    public function setStatus(string $status): DocumentInterface
    {
        return $this->setData(self::STATUS, $status);
    }

    public function getIsActive(): bool
    {
        return $this->getData(self::IS_ACTIVE) !== null;
    }

    public function setIsActive(bool $isActive): DocumentInterface
    {
        // NULL (not 0) to opt out of the unique index preventing double execution
        return $this->setData(self::IS_ACTIVE, $isActive ? 1 : null);
    }

    public function getCallbackTokenHash(): ?string
    {
        $value = $this->getData(self::CALLBACK_TOKEN);
        return $value === null || $value === '' ? null : (string)$value;
    }

    public function setCallbackTokenHash(?string $hash): DocumentInterface
    {
        return $this->setData(self::CALLBACK_TOKEN, $hash);
    }

    public function getTriggerCode(): string
    {
        return (string)$this->getData(self::TRIGGER_CODE);
    }

    public function setTriggerCode(string $triggerCode): DocumentInterface
    {
        return $this->setData(self::TRIGGER_CODE, $triggerCode);
    }

    public function getPdfPath(): ?string
    {
        $value = $this->getData(self::PDF_PATH);
        return $value === null || $value === '' ? null : (string)$value;
    }

    public function setPdfPath(?string $pdfPath): DocumentInterface
    {
        return $this->setData(self::PDF_PATH, $pdfPath);
    }

    public function getSignedPdfPath(): ?string
    {
        $value = $this->getData(self::SIGNED_PDF_PATH);
        return $value === null || $value === '' ? null : (string)$value;
    }

    public function setSignedPdfPath(?string $signedPdfPath): DocumentInterface
    {
        return $this->setData(self::SIGNED_PDF_PATH, $signedPdfPath);
    }

    public function getSignerEmail(): ?string
    {
        $value = $this->getData(self::SIGNER_EMAIL);
        return $value === null || $value === '' ? null : (string)$value;
    }

    public function setSignerEmail(?string $signerEmail): DocumentInterface
    {
        return $this->setData(self::SIGNER_EMAIL, $signerEmail);
    }

    public function getSignerPhone(): ?string
    {
        $value = $this->getData(self::SIGNER_PHONE);
        return $value === null || $value === '' ? null : (string)$value;
    }

    public function setSignerPhone(?string $signerPhone): DocumentInterface
    {
        return $this->setData(self::SIGNER_PHONE, $signerPhone);
    }

    public function getErrorMessage(): ?string
    {
        $value = $this->getData(self::ERROR_MESSAGE);
        return $value === null || $value === '' ? null : (string)$value;
    }

    public function setErrorMessage(?string $errorMessage): DocumentInterface
    {
        return $this->setData(self::ERROR_MESSAGE, $errorMessage);
    }

    public function getRetryCount(): int
    {
        return (int)$this->getData(self::RETRY_COUNT);
    }

    public function setRetryCount(int $retryCount): DocumentInterface
    {
        return $this->setData(self::RETRY_COUNT, $retryCount);
    }

    public function getPurgedAt(): ?string
    {
        $value = $this->getData(self::PURGED_AT);
        return $value === null || $value === '' ? null : (string)$value;
    }

    public function setPurgedAt(?string $purgedAt): DocumentInterface
    {
        return $this->setData(self::PURGED_AT, $purgedAt);
    }

    public function getReminderCount(): int
    {
        return (int)$this->getData(self::REMINDER_COUNT);
    }

    public function setReminderCount(int $reminderCount): DocumentInterface
    {
        return $this->setData(self::REMINDER_COUNT, $reminderCount);
    }

    public function getLastReminderAt(): ?string
    {
        $value = $this->getData(self::LAST_REMINDER_AT);
        return $value === null || $value === '' ? null : (string)$value;
    }

    public function setLastReminderAt(?string $lastReminderAt): DocumentInterface
    {
        return $this->setData(self::LAST_REMINDER_AT, $lastReminderAt);
    }

    public function getEscalationSentAt(): ?string
    {
        $value = $this->getData(self::ESCALATION_SENT_AT);
        return $value === null || $value === '' ? null : (string)$value;
    }

    public function setEscalationSentAt(?string $escalationSentAt): DocumentInterface
    {
        return $this->setData(self::ESCALATION_SENT_AT, $escalationSentAt);
    }

    public function getUpdatedAt(): string
    {
        return (string)$this->getData('updated_at');
    }

    public function setUpdatedAt(string $updatedAt): DocumentInterface
    {
        return $this->setData('updated_at', $updatedAt);
    }
}
