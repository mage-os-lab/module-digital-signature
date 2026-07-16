<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class TemplateActions extends Column
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
            if (!isset($item['template_id'])) {
                continue;
            }
            $item[$this->getData('name')] = [
                'edit' => [
                    'href' => $this->urlBuilder->getUrl(
                        'digitalsignature/template/edit',
                        ['template_id' => $item['template_id']]
                    ),
                    'label' => __('Modifica'),
                ],
                'delete' => [
                    'href' => $this->urlBuilder->getUrl(
                        'digitalsignature/template/delete',
                        ['template_id' => $item['template_id']]
                    ),
                    'label' => __('Elimina'),
                    'confirm' => [
                        'title' => __('Elimina template'),
                        'message' => __('Eliminare il template "%1"?', $item['name'] ?? ''),
                    ],
                    'post' => true,
                ],
            ];
        }

        return $dataSource;
    }
}
