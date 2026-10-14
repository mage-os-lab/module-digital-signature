<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Ui\Component\Listing\Column;

use MageOS\DigitalSignature\Api\Data\DossierExportInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Actions for the Dossier exports grid: download (ready only) and delete.
 */
class DossierExportActions extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        foreach ($dataSource['data']['items'] as &$item) {
            if (!isset($item['export_id'])) {
                continue;
            }
            $exportId = (int)$item['export_id'];
            $actions = [];

            if (($item['status'] ?? '') === DossierExportInterface::STATUS_READY) {
                $actions['download'] = [
                    'href' => $this->urlBuilder->getUrl('digitalsignature/dossierexport/download', ['id' => $exportId]),
                    'label' => __('Download'),
                ];
            }

            $actions['delete'] = [
                'href' => $this->urlBuilder->getUrl('digitalsignature/dossierexport/delete', ['id' => $exportId]),
                'label' => __('Delete'),
                'post' => true,
                'confirm' => [
                    'title' => __('Delete dossier export #%1', $exportId),
                    'message' => __('The ZIP file is deleted immediately. Continue?'),
                ],
            ];

            $item[$this->getData('name')] = $actions;
        }

        return $dataSource;
    }
}
