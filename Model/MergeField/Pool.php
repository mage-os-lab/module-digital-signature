<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\MergeField;

use MageOS\DigitalSignature\Api\MergeFieldPoolInterface;
use MageOS\DigitalSignature\Api\MergeFieldProviderInterface;
use Magento\Framework\Exception\LocalizedException;

class Pool implements MergeFieldPoolInterface
{
    /**
     * @param MergeFieldProviderInterface[] $providers injected via di.xml, key = code
     */
    public function __construct(private readonly array $providers = [])
    {
        foreach ($this->providers as $provider) {
            if (!$provider instanceof MergeFieldProviderInterface) {
                throw new \InvalidArgumentException(
                    sprintf('Merge field provider must implement %s', MergeFieldProviderInterface::class)
                );
            }
        }
    }

    public function get(string $code): MergeFieldProviderInterface
    {
        if (!$this->has($code)) {
            throw new LocalizedException(__('Merge field "%1" non registrato.', $code));
        }

        return $this->providers[$code];
    }

    public function getAll(): array
    {
        return $this->providers;
    }

    public function has(string $code): bool
    {
        return isset($this->providers[$code]);
    }
}
