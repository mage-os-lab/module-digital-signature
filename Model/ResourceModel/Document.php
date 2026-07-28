<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Document extends AbstractDb
{
    public const MAIN_TABLE = 'mageos_digitalsignature_document';
    public const LOG_TABLE = 'mageos_digitalsignature_document_log';

    protected function _construct(): void
    {
        $this->_init(self::MAIN_TABLE, 'document_id');
    }

    public function addLog(
        int $documentId,
        string $event,
        ?string $statusFrom = null,
        ?string $statusTo = null,
        ?string $message = null,
        ?string $payload = null
    ): void {
        $this->getConnection()->insert($this->getTable(self::LOG_TABLE), [
            'document_id' => $documentId,
            'event' => $event,
            'status_from' => $statusFrom,
            'status_to' => $statusTo,
            'message' => $message,
            'payload' => $payload,
        ]);
    }

    /**
     * GDPR retention: clears the raw payloads (which often contain PII) from
     * the document's event history, keeping event/status/message/date
     * for audit purposes.
     */
    public function clearLogPayloads(int $documentId): void
    {
        $this->getConnection()->update(
            $this->getTable(self::LOG_TABLE),
            ['payload' => null],
            ['document_id = ?' => $documentId]
        );
    }
}
