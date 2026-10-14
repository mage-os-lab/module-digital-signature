<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Dossier;

use MageOS\DigitalSignature\Model\Dossier\DossierFileLocator;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Math\Random;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DossierFileLocatorTest extends TestCase
{
    private WriteInterface&MockObject $varDir;
    private DossierFileLocator $locator;

    protected function setUp(): void
    {
        $this->varDir = $this->createMock(WriteInterface::class);
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturn($this->varDir);
        $random = $this->createStub(Random::class);
        $random->method('getRandomString')->willReturn('k3j9x0a1b2c3d4e5');

        $this->locator = new DossierFileLocator($filesystem, $random);
    }

    public function testNewRelativePathCreatesTheDirectoryAndUsesAnUnguessableName(): void
    {
        $this->varDir->expects(self::once())->method('create')->with('digitalsignature/dossiers');

        self::assertSame(
            'digitalsignature/dossiers/dossier-12-k3j9x0a1b2c3d4e5.zip',
            $this->locator->newRelativePath('dossier-12')
        );
    }

    public function testDeleteIsANoOpWhenTheFileIsMissing(): void
    {
        $this->varDir->method('isExist')->willReturn(false);
        $this->varDir->expects(self::never())->method('delete');

        $this->locator->delete('digitalsignature/dossiers/gone.zip');
    }

    public function testDeleteRemovesAnExistingFile(): void
    {
        $this->varDir->method('isExist')->willReturn(true);
        $this->varDir->expects(self::once())->method('delete')->with('digitalsignature/dossiers/a.zip');

        $this->locator->delete('digitalsignature/dossiers/a.zip');
    }

    public function testSizeReadsTheFileStat(): void
    {
        $this->varDir->method('stat')->with('digitalsignature/dossiers/a.zip')->willReturn(['size' => 4096]);

        self::assertSame(4096, $this->locator->size('digitalsignature/dossiers/a.zip'));
    }

    public function testDeletesOnlyStaleTemporaryFiles(): void
    {
        $this->varDir->method('isExist')->willReturn(true);
        $this->varDir->method('read')->with('digitalsignature/dossiers')->willReturn([
            'digitalsignature/dossiers/tmp-old.zip',
            'digitalsignature/dossiers/tmp-new.zip',
            'digitalsignature/dossiers/dossier-3-old.zip',
        ]);
        $this->varDir->method('stat')->willReturnMap([
            ['digitalsignature/dossiers/tmp-old.zip', ['mtime' => 100]],
            ['digitalsignature/dossiers/tmp-new.zip', ['mtime' => 900]],
            ['digitalsignature/dossiers/dossier-3-old.zip', ['mtime' => 100]],
        ]);
        $this->varDir->expects(self::once())->method('delete')->with('digitalsignature/dossiers/tmp-old.zip');

        self::assertSame(1, $this->locator->deleteTemporaryFilesOlderThan(500));
    }
}
