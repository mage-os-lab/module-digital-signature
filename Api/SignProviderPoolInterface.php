<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Api;

use Magento\Framework\Exception\NoSuchEntityException;

interface SignProviderPoolInterface
{
    /**
     * @return SignProviderInterface[] indicizzati per codice
     */
    public function getProviders(): array;

    /**
     * @throws NoSuchEntityException se il codice non è registrato
     */
    public function get(string $code): SignProviderInterface;
}
