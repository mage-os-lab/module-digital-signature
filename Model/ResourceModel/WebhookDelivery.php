<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class WebhookDelivery extends AbstractDb
{
    public const MAIN_TABLE = 'mageos_digitalsignature_webhook_delivery';
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENDING = 'sending';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';

    protected function _construct(): void
    {
        $this->_init(self::MAIN_TABLE, 'delivery_id');
    }

    public function create(int $subscriptionId, int $documentId, string $eventType, string $payload): int
    {
        $this->getConnection()->insert($this->getTable(self::MAIN_TABLE), [
            'subscription_id' => $subscriptionId,
            'document_id' => $documentId,
            'event_type' => $eventType,
            'payload' => $payload,
            'status' => self::STATUS_PENDING,
        ]);

        return (int)$this->getConnection()->lastInsertId($this->getTable(self::MAIN_TABLE));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getById(int $deliveryId): ?array
    {
        $select = $this->getConnection()->select()
            ->from($this->getTable(self::MAIN_TABLE))
            ->where('delivery_id = ?', $deliveryId);
        $row = $this->getConnection()->fetchRow($select);

        return $row ?: null;
    }

    /**
     * Atomic claim: marks the row as "in delivery" only if it is still
     * pending, so two parallel consumers don't send the same POST.
     *
     * @param int $deliveryId
     * @return bool true if the claim succeeded (row taken over)
     */
    public function claim(int $deliveryId): bool
    {
        $affected = $this->getConnection()->update(
            $this->getTable(self::MAIN_TABLE),
            ['status' => self::STATUS_SENDING],
            [
                'delivery_id = ?' => $deliveryId,
                'status = ?' => self::STATUS_PENDING,
            ]
        );

        return (int)$affected > 0;
    }

    /**
     * The payload is written after the insert because it includes delivery_id.
     *
     * @param int $deliveryId
     * @param string $payload
     * @return void
     */
    public function setPayload(int $deliveryId, string $payload): void
    {
        $this->getConnection()->update(
            $this->getTable(self::MAIN_TABLE),
            ['payload' => $payload],
            ['delivery_id = ?' => $deliveryId]
        );
    }

    public function markDelivered(int $deliveryId): void
    {
        $this->getConnection()->update(
            $this->getTable(self::MAIN_TABLE),
            ['status' => self::STATUS_DELIVERED],
            ['delivery_id = ?' => $deliveryId]
        );
    }

    public function scheduleRetry(int $deliveryId, int $attempts, string $error, string $nextAttemptAt): void
    {
        $this->getConnection()->update($this->getTable(self::MAIN_TABLE), [
            'status' => self::STATUS_PENDING,
            'attempts' => $attempts,
            'last_error' => $error,
            'next_attempt_at' => $nextAttemptAt,
        ], ['delivery_id = ?' => $deliveryId]);
    }

    public function markFailed(int $deliveryId, int $attempts, string $error): void
    {
        $this->getConnection()->update($this->getTable(self::MAIN_TABLE), [
            'status' => self::STATUS_FAILED,
            'attempts' => $attempts,
            'last_error' => $error,
            'next_attempt_at' => null,
        ], ['delivery_id = ?' => $deliveryId]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getDuePending(int $limit): array
    {
        $select = $this->getConnection()->select()
            ->from($this->getTable(self::MAIN_TABLE))
            ->where('status = ?', self::STATUS_PENDING)
            ->where('next_attempt_at IS NULL OR next_attempt_at <= ?', (new \DateTime())->format('Y-m-d H:i:s'))
            ->limit($limit);

        return $this->getConnection()->fetchAll($select);
    }
}
