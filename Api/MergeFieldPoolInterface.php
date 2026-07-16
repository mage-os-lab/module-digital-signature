<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Api;

use Magento\Framework\Exception\LocalizedException;

interface MergeFieldPoolInterface
{
    /**
     * Get a merge field provider by its code.
     *
     * @param string $code
     * @return MergeFieldProviderInterface
     * @throws LocalizedException
     */
    public function get(string $code): MergeFieldProviderInterface;

    /**
     * Get all registered merge field providers.
     *
     * @return MergeFieldProviderInterface[]
     */
    public function getAll(): array;

    /**
     * Check if a merge field provider is registered.
     *
     * @param string $code
     * @return bool
     */
    public function has(string $code): bool;
}
