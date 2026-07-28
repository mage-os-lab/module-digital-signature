<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\ResourceModel;

use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Context;

class WebhookSubscription extends AbstractDb
{
    public const MAIN_TABLE = 'mageos_digitalsignature_webhook_subscription';

    public function __construct(
        Context $context,
        private readonly EncryptorInterface $encryptor,
        $connectionName = null
    ) {
        parent::__construct($context, $connectionName);
    }

    protected function _construct(): void
    {
        $this->_init(self::MAIN_TABLE, 'subscription_id');
    }

    protected function _beforeSave(AbstractModel $object)
    {
        if ($object->isObjectNew() || $object->dataHasChangedFor('secret')) {
            $secret = (string)$object->getData('secret');
            if ($secret !== '') {
                $object->setData('secret', $this->encryptor->encrypt($secret));
            }
        }

        return parent::_beforeSave($object);
    }

    /**
     * Active rows for the document's store (including global ones,
     * store_id NULL). The secret remains encrypted: it is decrypted only at
     * send time, by the Consumer (Task 16).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getActiveForStore(?int $storeId): array
    {
        $select = $this->getConnection()->select()
            ->from($this->getTable(self::MAIN_TABLE))
            ->where('enabled = 1')
            ->where('store_id IS NULL OR store_id = ?', $storeId);

        return $this->getConnection()->fetchAll($select);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getRowById(int $subscriptionId): ?array
    {
        $select = $this->getConnection()->select()
            ->from($this->getTable(self::MAIN_TABLE))
            ->where('subscription_id = ?', $subscriptionId);
        $row = $this->getConnection()->fetchRow($select);

        return $row ?: null;
    }
}
