<?php

declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Document\Export;

use MageOS\DigitalSignature\Model\Document\Export\ConvertToXlsx;
use Magento\Framework\Api\Search\DocumentInterface as SearchDocumentInterface;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\DataProviderInterface;
use Magento\Framework\View\Element\UiComponentInterface;
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
    /** @var array<int, string[]> row values by listing document object id */
    private array $rowsByDocument = [];

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

    /**
     * Listing component double paging over $rows (2 rows per page, like the
     * real data provider would after setPageSize()). Each row becomes a
     * listing document; $rowsByDocument lets getRowData() return the row.
     *
     * @param array<int, string[]> $rows
     */
    private function buildComponent(array $rows): UiComponentInterface
    {
        $documents = [];
        foreach ($rows as $id => $row) {
            $document = $this->createMock(SearchDocumentInterface::class);
            $document->method('getId')->willReturn($id);
            $documents[$id] = $document;
            $this->rowsByDocument[spl_object_id($document)] = $row;
        }

        $page = new \ArrayObject(['current' => 1]);
        $dataProvider = $this->createMock(DataProviderInterface::class);
        $dataProvider->method('getSearchCriteria')->willReturn(
            new FakeSearchCriteria(static function (int $current) use ($page): void {
                $page['current'] = $current;
            })
        );
        $dataProvider->method('getSearchResult')->willReturnCallback(
            static function () use ($documents, $page): object {
                $pageSize = 2;
                $items = array_slice($documents, ($page['current'] - 1) * $pageSize, $pageSize, true);

                return new class($items, count($documents)) {
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
        );

        $context = $this->createMock(ContextInterface::class);
        $context->method('getDataProvider')->willReturn($dataProvider);

        $component = $this->createMock(UiComponentInterface::class);
        $component->method('getName')->willReturn('digitalsignature_document_listing');
        $component->method('getContext')->willReturn($context);

        return $component;
    }

    private function rowOf(SearchDocumentInterface $document): array
    {
        return $this->rowsByDocument[spl_object_id($document)];
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
            ->willReturnCallback(fn (SearchDocumentInterface $document) => $this->rowOf($document));

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
            ->willReturnCallback(fn (SearchDocumentInterface $document) => $this->rowOf($document));

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
    /** @param callable(int): void $onPageChange */
    public function __construct(private $onPageChange)
    {
    }

    public function setCurrentPage($currentPage): self
    {
        ($this->onPageChange)((int) $currentPage);

        return $this;
    }

    public function setPageSize($pageSize): self
    {
        return $this;
    }
}
