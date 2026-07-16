<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Queue;

use Magento\Framework\MessageQueue\PublisherInterface;

/**
 * Pubblica i job documento. I messaggi contengono SOLO l'id (decisione di
 * analisi: payload minimale, lo stato si ricarica sempre dal DB).
 */
class Publisher
{
    public const TOPIC_PROCESS = 'digitalsignature.document.process';
    public const TOPIC_REFRESH = 'digitalsignature.document.refresh';

    public function __construct(private readonly PublisherInterface $publisher)
    {
    }

    public function publishProcess(int $documentId): void
    {
        $this->publisher->publish(self::TOPIC_PROCESS, (string)$documentId);
    }

    public function publishRefresh(int $documentId): void
    {
        $this->publisher->publish(self::TOPIC_REFRESH, (string)$documentId);
    }
}
