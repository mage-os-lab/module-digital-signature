<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Provider;

use MageOS\DigitalSignature\Api\SignProviderInterface;
use MageOS\DigitalSignature\Api\SignProviderPoolInterface;
use Magento\Framework\Exception\NoSuchEntityException;

class Pool implements SignProviderPoolInterface
{
    /**
     * @param SignProviderInterface[] $providers iniettati via di.xml, chiave = codice
     */
    public function __construct(private readonly array $providers = [])
    {
        foreach ($this->providers as $provider) {
            if (!$provider instanceof SignProviderInterface) {
                throw new \InvalidArgumentException(
                    sprintf('Sign provider must implement %s', SignProviderInterface::class)
                );
            }
        }
    }

    public function getProviders(): array
    {
        return $this->providers;
    }

    public function get(string $code): SignProviderInterface
    {
        if (!isset($this->providers[$code])) {
            throw new NoSuchEntityException(__('Provider di firma "%1" non registrato.', $code));
        }

        return $this->providers[$code];
    }
}
