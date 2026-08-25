<?php

declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Document\Export;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Ui\Component\MassAction\Filter;
use Magento\Ui\Model\Export\MetadataProvider;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Builds an XLSX export of the Document grid, mirroring the column set,
 * active filters and pagination of the core CSV export
 * (Magento\Ui\Model\Export\ConvertToCsv) since there is no core XLSX writer.
 */
class ConvertToXlsx
{
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly Filter $filter,
        private readonly MetadataProvider $metadataProvider,
        private readonly int $pageSize = 200
    ) {
    }

    /**
     * @return array{type: string, value: string, rm: bool}
     * @throws LocalizedException
     */
    public function getXlsxFile(): array
    {
        $component = $this->filter->getComponent();
        $this->filter->prepareComponent($component);
        $this->filter->applySelectionOnTargetProvider();

        $dataProvider = $component->getContext()->getDataProvider();
        $fields = $this->metadataProvider->getFields($component);
        $options = $this->metadataProvider->getOptions();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $headerColumn = 1;
        foreach ($this->metadataProvider->getHeaders($component) as $header) {
            $sheet->setCellValueExplicit([$headerColumn++, 1], (string) $header, DataType::TYPE_STRING);
        }

        $rowNumber = 2;
        $page = 1;
        $searchCriteria = $dataProvider->getSearchCriteria()
            ->setCurrentPage($page)
            ->setPageSize($this->pageSize);
        $totalCount = (int) $dataProvider->getSearchResult()->getTotalCount();

        while ($totalCount > 0) {
            $items = $dataProvider->getSearchResult()->getItems();
            foreach ($items as $item) {
                $this->metadataProvider->convertDate($item, $component->getName());
                $column = 1;
                foreach ($this->metadataProvider->getRowData($item, $fields, $options) as $value) {
                    $sheet->setCellValueExplicit([$column++, $rowNumber], (string) $value, DataType::TYPE_STRING);
                }
                $rowNumber++;
            }
            $searchCriteria->setCurrentPage(++$page);
            $totalCount -= $this->pageSize;
        }

        $directory = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $directory->create('export');
        $fileName = 'export/' . $component->getName() . md5(microtime()) . '.xlsx';

        (new Xlsx($spreadsheet))->save($directory->getAbsolutePath($fileName));

        return [
            'type' => 'filename',
            'value' => $fileName,
            'rm' => true,
        ];
    }
}
