<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Provider;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\ScopeInterface;

/**
 * Configuration reader for the connectors: digital_signature/providers/<code>/<field>.
 */
class ProviderConfig
{
    private const PATH_PREFIX = 'digital_signature/providers/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly Json $json
    ) {
    }

    public function get(string $providerCode, string $field, ?int $storeId = null): ?string
    {
        $value = $this->scopeConfig->getValue(
            self::PATH_PREFIX . $providerCode . '/' . $field,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $value === null ? null : (string)$value;
    }

    public function isSetFlag(string $providerCode, string $field, ?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::PATH_PREFIX . $providerCode . '/' . $field,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Field saved with the Encrypted backend model.
     */
    public function getSecret(string $providerCode, string $field, ?int $storeId = null): ?string
    {
        $value = $this->get($providerCode, $field, $storeId);
        if ($value === null || $value === '') {
            return null;
        }

        return $this->encryptor->decrypt($value);
    }

    /**
     * Field saved with the ArraySerialized backend model (dynamic rows).
     *
     * @return array<int|string, array<string, string>>
     */
    public function getSerialized(string $providerCode, string $field, ?int $storeId = null): array
    {
        $value = $this->get($providerCode, $field, $storeId);
        if ($value === null || $value === '') {
            return [];
        }
        try {
            $decoded = $this->json->unserialize($value);
        } catch (\InvalidArgumentException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
