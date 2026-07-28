<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\WebhookSubscription;

use MageOS\DigitalSignature\Model\ResourceModel\WebhookSubscription\CollectionFactory;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;

class DataProvider extends AbstractDataProvider
{
    private const PERSIST_KEY = 'digitalsignature_webhooksubscription';

    private ?array $loadedData = null;

    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        CollectionFactory $collectionFactory,
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

        foreach ($this->collection->getItems() as $subscription) {
            $data = $subscription->getData();
            // Never prefill the encrypted secret in the admin form (write-only / password)
            unset($data['secret']);
            $this->loadedData[$subscription->getId()] = $data;
        }

        $persisted = $this->dataPersistor->get(self::PERSIST_KEY);
        if (!empty($persisted)) {
            unset($persisted['secret']);
            $this->loadedData[$persisted['subscription_id'] ?? ''] = $persisted;
            $this->dataPersistor->clear(self::PERSIST_KEY);
        }

        return $this->loadedData;
    }
}
