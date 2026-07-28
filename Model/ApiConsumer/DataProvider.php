<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\ApiConsumer;

use MageOS\DigitalSignature\Api\ApiConsumerRepositoryInterface;
use MageOS\DigitalSignature\Model\ResourceModel\ApiConsumer\CollectionFactory;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;

class DataProvider extends AbstractDataProvider
{
    private const PERSIST_KEY = 'digitalsignature_apiconsumer';

    private ?array $loadedData = null;

    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        CollectionFactory $collectionFactory,
        private readonly ApiConsumerRepositoryInterface $apiConsumerRepository,
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

        foreach ($this->collection->getItems() as $consumer) {
            $data = $consumer->getData();
            $data['store_ids'] = $this->apiConsumerRepository->getStoreIds((int)$consumer->getId());
            $this->loadedData[$consumer->getId()] = $data;
        }

        $persisted = $this->dataPersistor->get(self::PERSIST_KEY);
        if (!empty($persisted)) {
            $this->loadedData[$persisted['consumer_id'] ?? ''] = $persisted;
            $this->dataPersistor->clear(self::PERSIST_KEY);
        }

        return $this->loadedData;
    }
}
