<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model;

use MageOS\DigitalSignature\Api\Data\AuditTrailInterface;
use MageOS\DigitalSignature\Model\ResourceModel\AuditTrail as AuditTrailResource;
use Magento\Framework\Model\AbstractModel;

class AuditTrail extends AbstractModel implements AuditTrailInterface
{
    protected $_eventPrefix = 'digitalsignature_audit_trail';

    protected function _construct(): void
    {
        $this->_init(AuditTrailResource::class);
    }

    public function getDocumentId(): ?int
    {
        $id = $this->getData(self::DOCUMENT_ID);
        return $id === null ? null : (int)$id;
    }

    public function setDocumentId(int $documentId): AuditTrailInterface
    {
        return $this->setData(self::DOCUMENT_ID, $documentId);
    }

    public function getProviderCode(): string
    {
        return (string)$this->getData(self::PROVIDER_CODE);
    }

    public function setProviderCode(string $providerCode): AuditTrailInterface
    {
        return $this->setData(self::PROVIDER_CODE, $providerCode);
    }

    public function getTransactionId(): ?string
    {
        $value = $this->getData(self::TRANSACTION_ID);
        return $value === null || $value === '' ? null : (string)$value;
    }

    public function setTransactionId(?string $transactionId): AuditTrailInterface
    {
        return $this->setData(self::TRANSACTION_ID, $transactionId);
    }

    public function getHashBeforeSigning(): ?string
    {
        $value = $this->getData(self::HASH_BEFORE_SIGNING);
        return $value === null || $value === '' ? null : (string)$value;
    }

    public function setHashBeforeSigning(?string $hash): AuditTrailInterface
    {
        return $this->setData(self::HASH_BEFORE_SIGNING, $hash);
    }

    public function getHashAfterSigning(): ?string
    {
        $value = $this->getData(self::HASH_AFTER_SIGNING);
        return $value === null || $value === '' ? null : (string)$value;
    }

    public function setHashAfterSigning(?string $hash): AuditTrailInterface
    {
        return $this->setData(self::HASH_AFTER_SIGNING, $hash);
    }

    public function getCertificatePath(): ?string
    {
        $value = $this->getData(self::CERTIFICATE_PATH);
        return $value === null || $value === '' ? null : (string)$value;
    }

    public function setCertificatePath(?string $certificatePath): AuditTrailInterface
    {
        return $this->setData(self::CERTIFICATE_PATH, $certificatePath);
    }

    public function getSignerIp(): ?string
    {
        $value = $this->getData(self::SIGNER_IP);
        return $value === null || $value === '' ? null : (string)$value;
    }

    public function setSignerIp(?string $signerIp): AuditTrailInterface
    {
        return $this->setData(self::SIGNER_IP, $signerIp);
    }

    public function getAuthenticationMethod(): ?string
    {
        $value = $this->getData(self::AUTHENTICATION_METHOD);
        return $value === null || $value === '' ? null : (string)$value;
    }

    public function setAuthenticationMethod(?string $authenticationMethod): AuditTrailInterface
    {
        return $this->setData(self::AUTHENTICATION_METHOD, $authenticationMethod);
    }

    public function getFetchStatus(): string
    {
        return (string)$this->getData(self::FETCH_STATUS);
    }

    public function setFetchStatus(string $fetchStatus): AuditTrailInterface
    {
        return $this->setData(self::FETCH_STATUS, $fetchStatus);
    }

    public function getFetchAttempts(): int
    {
        return (int)$this->getData(self::FETCH_ATTEMPTS);
    }

    public function setFetchAttempts(int $fetchAttempts): AuditTrailInterface
    {
        return $this->setData(self::FETCH_ATTEMPTS, $fetchAttempts);
    }

    public function getLastError(): ?string
    {
        $value = $this->getData(self::LAST_ERROR);
        return $value === null || $value === '' ? null : (string)$value;
    }

    public function setLastError(?string $lastError): AuditTrailInterface
    {
        return $this->setData(self::LAST_ERROR, $lastError);
    }

    public function getFetchedAt(): ?string
    {
        $value = $this->getData(self::FETCHED_AT);
        return $value === null || $value === '' ? null : (string)$value;
    }

    public function setFetchedAt(?string $fetchedAt): AuditTrailInterface
    {
        return $this->setData(self::FETCHED_AT, $fetchedAt);
    }
}
