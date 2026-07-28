<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Api;

use Magento\Framework\Exception\FileSystemException;

/**
 * Storage for generated/signed PDFs (pluggable interface: today a protected
 * local filesystem, in the future S3 or other backends).
 */
interface DocumentStorageInterface
{
    /**
     * @param string $relativePath path relative to the document storage
     * @throws FileSystemException
     */
    public function write(string $relativePath, string $content): void;

    /**
     * @throws FileSystemException
     */
    public function read(string $relativePath): string;

    public function exists(string $relativePath): bool;

    /**
     * Deletes the file, if present. Idempotent: no error if the path
     * doesn't already exist (retention/cleanup can be rerun).
     *
     * @throws FileSystemException
     */
    public function delete(string $relativePath): void;
}
