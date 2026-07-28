<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Api;

use Magento\Framework\Exception\NoSuchEntityException;

interface SignProviderPoolInterface
{
    /**
     * @return SignProviderInterface[] indexed by code
     */
    public function getProviders(): array;

    /**
     * @throws NoSuchEntityException if the code is not registered
     */
    public function get(string $code): SignProviderInterface;
}
