<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Storage;

use MageOS\DigitalSignature\Api\DocumentStorageInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;

/**
 * Storage su var/digitalsignature/: directory non servita dal web server,
 * il download passa sempre da controller autorizzati (analisi §12).
 */
class VarDocumentStorage implements DocumentStorageInterface
{
    private const BASE_DIR = 'digitalsignature';

    public function __construct(private readonly Filesystem $filesystem)
    {
    }

    public function write(string $relativePath, string $content): void
    {
        $dir = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $dir->writeFile($this->path($relativePath), $content);
    }

    public function read(string $relativePath): string
    {
        $dir = $this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR);
        $path = $this->path($relativePath);
        if (!$dir->isExist($path)) {
            throw new FileSystemException(__('File documento non trovato nello storage: %1', $relativePath));
        }

        return $dir->readFile($path);
    }

    public function exists(string $relativePath): bool
    {
        return $this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR)
            ->isExist($this->path($relativePath));
    }

    public function delete(string $relativePath): void
    {
        $dir = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $path = $this->path($relativePath);
        if ($dir->isExist($path)) {
            $dir->delete($path);
        }
    }

    private function path(string $relativePath): string
    {
        // Il path arriva sempre dai record documento, mai da input utente;
        // la normalizzazione è comunque una difesa in profondità
        $clean = str_replace(['..', "\0"], '', $relativePath);

        return self::BASE_DIR . '/' . ltrim($clean, '/');
    }
}
