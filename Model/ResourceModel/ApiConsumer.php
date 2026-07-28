<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class ApiConsumer extends AbstractDb
{
    public const MAIN_TABLE = 'mageos_digitalsignature_api_consumer';
    public const STORE_TABLE = 'mageos_digitalsignature_api_consumer_store';

    protected function _construct(): void
    {
        $this->_init(self::MAIN_TABLE, 'consumer_id');
    }

    /**
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function loadByIntegrationId(\MageOS\DigitalSignature\Model\ApiConsumer $consumer, int $integrationId): void
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getTable(self::MAIN_TABLE))
            ->where('integration_id = ?', $integrationId);
        $row = $connection->fetchRow($select);
        if ($row === false) {
            return;
        }
        $consumer->setData($row);
    }

    /**
     * @return int[]
     */
    public function getStoreIds(int $consumerId): array
    {
        $select = $this->getConnection()->select()
            ->from($this->getTable(self::STORE_TABLE), 'store_id')
            ->where('consumer_id = ?', $consumerId);

        return array_map('intval', $this->getConnection()->fetchCol($select));
    }

    /**
     * @param int[] $storeIds
     */
    public function saveStoreIds(int $consumerId, array $storeIds): void
    {
        $connection = $this->getConnection();
        $table = $this->getTable(self::STORE_TABLE);
        $storeIds = array_unique(array_map('intval', $storeIds));
        $current = $this->getStoreIds($consumerId);

        $toDelete = array_diff($current, $storeIds);
        if ($toDelete) {
            $connection->delete($table, ['consumer_id = ?' => $consumerId, 'store_id IN (?)' => $toDelete]);
        }

        $toInsert = array_diff($storeIds, $current);
        if ($toInsert) {
            $rows = [];
            foreach ($toInsert as $storeId) {
                $rows[] = ['consumer_id' => $consumerId, 'store_id' => $storeId];
            }
            $connection->insertMultiple($table, $rows);
        }
    }
}
