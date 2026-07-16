<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Api;

use Magento\Framework\Exception\FileSystemException;

/**
 * Storage dei PDF generati/firmati (interfaccia pluggable: oggi filesystem
 * locale protetto, in futuro S3 o altri backend).
 */
interface DocumentStorageInterface
{
    /**
     * @param string $relativePath path relativo allo storage documenti
     * @throws FileSystemException
     */
    public function write(string $relativePath, string $content): void;

    /**
     * @throws FileSystemException
     */
    public function read(string $relativePath): string;

    public function exists(string $relativePath): bool;

    /**
     * Elimina il file, se presente. Idempotente: nessun errore se il path
     * non esiste già (retention/pulizia possono essere rieseguite).
     *
     * @throws FileSystemException
     */
    public function delete(string $relativePath): void;
}
