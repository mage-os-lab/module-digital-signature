<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Ui\Component\Listing\Column;

use MageOS\DigitalSignature\Api\Data\AuditTrailInterface;
use MageOS\DigitalSignature\Model\ResourceModel\AuditTrail\CollectionFactory as AuditTrailCollectionFactory;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Actions for the signature documents grid: order view and download of the
 * generated/signed PDF and of the provider's audit trail certificate
 * (regeneration stays in the order view tab, where it is a POST with form key).
 */
class DocumentActions extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        private readonly AuditTrailCollectionFactory $auditTrailCollectionFactory,
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

        $withCertificate = $this->documentIdsWithCertificate($dataSource['data']['items']);

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
                    'label' => __('View order'),
                ];
            }

            if (!empty($item['signed_pdf_path'])) {
                $actions['download_signed'] = [
                    'href' => $this->urlBuilder->getUrl(
                        'digitalsignature/document/download',
                        ['id' => $documentId, 'type' => 'signed']
                    ),
                    'label' => __('Download signed'),
                ];
            }

            if (!empty($item['pdf_path'])) {
                $actions['download_generated'] = [
                    'href' => $this->urlBuilder->getUrl(
                        'digitalsignature/document/download',
                        ['id' => $documentId, 'type' => 'generated']
                    ),
                    'label' => __('Download generated'),
                ];
            }

            if (isset($withCertificate[$documentId])) {
                $actions['download_certificate'] = [
                    'href' => $this->urlBuilder->getUrl(
                        'digitalsignature/document/download',
                        ['id' => $documentId, 'type' => 'certificate']
                    ),
                    'label' => __('Download certificate'),
                ];
            }

            $item[$this->getData('name')] = $actions;
        }

        return $dataSource;
    }

    /**
     * One query per grid page: the IDs of the listed documents whose provider
     * certificate has been fetched and is still stored (not purged).
     *
     * @return array<int, true>
     */
    private function documentIdsWithCertificate(array $items): array
    {
        $documentIds = array_values(array_filter(array_map(
            static fn(array $item): int => (int)($item['document_id'] ?? 0),
            $items
        )));
        if (!$documentIds) {
            return [];
        }

        $collection = $this->auditTrailCollectionFactory->create();
        $collection->addFieldToFilter('document_id', ['in' => $documentIds]);
        $collection->addFieldToFilter('fetch_status', AuditTrailInterface::FETCH_STATUS_FETCHED);
        $collection->addFieldToFilter('certificate_path', ['notnull' => true]);

        $result = [];
        foreach ($collection as $auditTrail) {
            $result[(int)$auditTrail->getDocumentId()] = true;
        }

        return $result;
    }
}
