<?php

declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Document\Export;

use MageOS\DigitalSignature\Model\Document\Export\ConvertToXlsx;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Ui\Component\MassAction\Filter;
use Magento\Ui\Model\Export\MetadataProvider;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConvertToXlsxTest extends TestCase
{
    private Filesystem&MockObject $filesystem;
    private Filter&MockObject $filter;
    private MetadataProvider&MockObject $metadataProvider;
    private WriteInterface&MockObject $directory;
    private string $tempFile;

    protected function setUp(): void
    {
        $this->filesystem = $this->createMock(Filesystem::class);
        $this->filter = $this->createMock(Filter::class);
        $this->metadataProvider = $this->createMock(MetadataProvider::class);
        $this->directory = $this->createMock(WriteInterface::class);
        $this->filesystem->method('getDirectoryWrite')->willReturn($this->directory);

        $this->tempFile = sys_get_temp_dir() . '/convert_to_xlsx_test_' . uniqid('', true) . '.xlsx';
    }

    protected function tearDown(): void
    {
        if (is_file($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    private function buildComponent(array $rows): object
    {
        return new class($rows) {
            public function __construct(private array $rows)
            {
            }

            public function getName(): string
            {
                return 'digitalsignature_document_listing';
            }

            public function getContext(): object
            {
                return new class($this->rows) {
                    public function __construct(private array $rows)
                    {
                    }

                    public function getDataProvider(): object
                    {
                        return new class($this->rows) implements \IteratorAggregate {
                            private int $page = 1;

                            public function __construct(private array $rows)
                            {
                            }

                            public function getSearchCriteria(): FakeSearchCriteria
                            {
                                return new FakeSearchCriteria($this);
                            }

                            public function setPage(int $page): void
                            {
                                $this->page = $page;
                            }

                            public function getSearchResult(): object
                            {
                                $pageSize = 2;
                                $offset = ($this->page - 1) * $pageSize;
                                $items = array_slice($this->rows, $offset, $pageSize, true);

                                return new class($items, count($this->rows)) {
                                    public function __construct(private array $items, private int $totalCount)
                                    {
                                    }

                                    public function getTotalCount(): int
                                    {
                                        return $this->totalCount;
                                    }

                                    public function getItems(): array
                                    {
                                        return $this->items;
                                    }
                                };
                            }

                            public function getIterator(): \Iterator
                            {
                                return new \ArrayIterator([]);
                            }
                        };
                    }
                };
            }
        };
    }

    public function testExportsHeaderAndAllPagesOfRows(): void
    {
        $rows = [
            1 => ['1', 'pending'],
            2 => ['2', 'signed'],
            3 => ['3', 'rejected'],
        ];
        $component = $this->buildComponent($rows);

        $this->filter->method('getComponent')->willReturn($component);
        $this->metadataProvider->method('getFields')->willReturn(['id', 'status']);
        $this->metadataProvider->method('getOptions')->willReturn([]);
        $this->metadataProvider->method('getHeaders')->willReturn(['ID', 'Status']);
        $this->metadataProvider->method('getRowData')
            ->willReturnCallback(static fn ($item) => $item);

        $this->directory->expects(self::once())->method('create')->with('export');
        $this->directory->expects(self::once())
            ->method('getAbsolutePath')
            ->with(self::matchesRegularExpression('#^export/digitalsignature_document_listing[0-9a-f]{32}\.xlsx$#'))
            ->willReturn($this->tempFile);

        $converter = new ConvertToXlsx($this->filesystem, $this->filter, $this->metadataProvider, 2);
        $result = $converter->getXlsxFile();

        self::assertSame('filename', $result['type']);
        self::assertTrue($result['rm']);
        self::assertStringStartsWith('export/digitalsignature_document_listing', $result['value']);
        self::assertFileExists($this->tempFile);

        $spreadsheet = IOFactory::load($this->tempFile);
        $sheet = $spreadsheet->getActiveSheet();
        self::assertSame('ID', $sheet->getCell('A1')->getValue());
        self::assertSame('Status', $sheet->getCell('B1')->getValue());
        self::assertSame('1', $sheet->getCell('A2')->getValue());
        self::assertSame('pending', $sheet->getCell('B2')->getValue());
        self::assertSame('3', $sheet->getCell('A4')->getValue());
        self::assertSame('rejected', $sheet->getCell('B4')->getValue());
    }

    public function testFormulaLikeValuesAreWrittenAsLiteralStrings(): void
    {
        $rows = [
            1 => ['=SUM(1,1)', '=cmd|\' /C calc\'!A1'],
        ];
        $component = $this->buildComponent($rows);

        $this->filter->method('getComponent')->willReturn($component);
        $this->metadataProvider->method('getFields')->willReturn(['id', 'status']);
        $this->metadataProvider->method('getOptions')->willReturn([]);
        $this->metadataProvider->method('getHeaders')->willReturn(['ID', 'Status']);
        $this->metadataProvider->method('getRowData')
            ->willReturnCallback(static fn ($item) => $item);

        $this->directory->method('getAbsolutePath')->willReturn($this->tempFile);

        $converter = new ConvertToXlsx($this->filesystem, $this->filter, $this->metadataProvider, 2);
        $converter->getXlsxFile();

        $spreadsheet = IOFactory::load($this->tempFile);
        $sheet = $spreadsheet->getActiveSheet();

        $formulaCell = $sheet->getCell('A2');
        self::assertSame(DataType::TYPE_STRING, $formulaCell->getDataType());
        self::assertSame('=SUM(1,1)', $formulaCell->getValue());

        $secondCell = $sheet->getCell('B2');
        self::assertSame(DataType::TYPE_STRING, $secondCell->getDataType());
        self::assertSame('=cmd|\' /C calc\'!A1', $secondCell->getValue());
    }
}

class FakeSearchCriteria
{
    public function __construct(private object $dataProvider)
    {
    }

    public function setCurrentPage($currentPage): self
    {
        $this->dataProvider->setPage((int) $currentPage);

        return $this;
    }

    public function setPageSize($pageSize): self
    {
        return $this;
    }
}
