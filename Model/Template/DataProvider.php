<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Template;

use MageOS\DigitalSignature\Model\ResourceModel\Template as TemplateResource;
use MageOS\DigitalSignature\Model\ResourceModel\Template\CollectionFactory;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Filesystem;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;

class DataProvider extends AbstractDataProvider
{
    private ?array $loadedData = null;

    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        CollectionFactory $collectionFactory,
        private readonly TemplateResource $templateResource,
        private readonly RequestInterface $request,
        private readonly StoreManagerInterface $storeManager,
        private readonly Filesystem $filesystem,
        private readonly DataPersistorInterface $dataPersistor,
        array $meta = [],
        array $data = []
    ) {
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
        $this->collection = $collectionFactory->create();
    }

    public function getData(): array
    {
        if ($this->loadedData !== null) {
            return $this->loadedData;
        }
        $this->loadedData = [];
        $storeId = (int)$this->request->getParam('store', 0);

        foreach ($this->collection->getItems() as $template) {
            $data = $template->getData();
            $data['store_id'] = $storeId;
            $data += $this->getPdfFileData((int)$template->getId(), $storeId);
            $this->loadedData[$template->getId()] = $data;
        }

        $persisted = $this->dataPersistor->get('digitalsignature_template');
        if (!empty($persisted)) {
            $this->loadedData[$persisted['template_id'] ?? ''] = $persisted;
            $this->dataPersistor->clear('digitalsignature_template');
        }

        return $this->loadedData;
    }

    public function getMeta(): array
    {
        $meta = parent::getMeta();
        $storeId = (int)$this->request->getParam('store', 0);
        // The "use default" checkbox only makes sense in specific store views
        $meta['pdf']['children']['pdf_use_default']['arguments']['data']['config']['visible'] = $storeId > 0;

        return $meta;
    }

    /**
     * Value for the fileUploader + "use default" flag computed on the fallback.
     */
    private function getPdfFileData(int $templateId, int $storeId): array
    {
        $row = $this->templateResource->getPdfPathForStore($templateId, $storeId);
        if ($row === null) {
            return ['pdf_use_default' => $storeId > 0 ? '1' : '0'];
        }
        [$effectiveStoreId, $pdfPath] = $row;

        $mediaDir = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
        $size = $mediaDir->isExist($pdfPath) ? $mediaDir->stat($pdfPath)['size'] : 0;
        $mediaUrl = $this->storeManager->getStore()
            ->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);

        return [
            'pdf_use_default' => ($storeId > 0 && $effectiveStoreId === 0) ? '1' : '0',
            'pdf_file' => [
                [
                    'name' => basename($pdfPath),
                    'url' => $mediaUrl . $pdfPath,
                    'size' => $size,
                    'type' => 'application/pdf',
                ],
            ],
        ];
    }
}
