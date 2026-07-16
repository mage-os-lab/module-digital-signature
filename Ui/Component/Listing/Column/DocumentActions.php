<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Azioni della griglia documenti firma: vista ordine e download del PDF
 * generato/firmato (la rigenerazione resta nel tab della vista ordine, dove
 * è una POST con form key).
 */
class DocumentActions extends Column
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
            if (!isset($item['document_id'])) {
                continue;
            }
            $documentId = (int)$item['document_id'];
            $actions = [];

            if (!empty($item['order_id'])) {
                $actions['view_order'] = [
                    'href' => $this->urlBuilder->getUrl(
                        'sales/order/view',
                        ['order_id' => (int)$item['order_id']]
                    ),
                    'label' => __('Vedi ordine'),
                ];
            }

            if (!empty($item['signed_pdf_path'])) {
                $actions['download_signed'] = [
                    'href' => $this->urlBuilder->getUrl(
                        'digitalsignature/document/download',
                        ['id' => $documentId, 'type' => 'signed']
                    ),
                    'label' => __('Scarica firmato'),
                ];
            }

            if (!empty($item['pdf_path'])) {
                $actions['download_generated'] = [
                    'href' => $this->urlBuilder->getUrl(
                        'digitalsignature/document/download',
                        ['id' => $documentId, 'type' => 'generated']
                    ),
                    'label' => __('Scarica generato'),
                ];
            }

            $item[$this->getData('name')] = $actions;
        }

        return $dataSource;
    }
}
