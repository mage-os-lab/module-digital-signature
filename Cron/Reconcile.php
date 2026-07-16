<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Cron;

use MageOS\DigitalSignature\Model\Document\Status;
use MageOS\DigitalSignature\Model\Queue\Publisher;
use MageOS\DigitalSignature\Model\ResourceModel\Document\CollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Psr\Log\LoggerInterface;

/**
 * Riconciliazione periodica (analisi §5):
 * - documenti "sent" fermi da troppo → refresh di stato (copre callback perse);
 * - documenti pending/generated bloccati con retry residui → riaccodati.
 * Il cap per run è il throttling verso il provider deciso in analisi.
 */
class Reconcile
{
    private const XML_PATH_BATCH_LIMIT = 'digital_signature/general/reconcile_batch_limit';
    private const XML_PATH_MAX_RETRIES = 'digital_signature/general/max_retries';

    private const STALE_SENT_MINUTES = 15;
    private const STALE_PROCESS_MINUTES = 10;

    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly Publisher $publisher,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $limit = max(1, (int)$this->scopeConfig->getValue(self::XML_PATH_BATCH_LIMIT));

        $refreshed = $this->dispatch(
            [Status::SENT],
            self::STALE_SENT_MINUTES,
            $limit,
            fn (int $id) => $this->publisher->publishRefresh($id)
        );

        $maxRetries = max(1, (int)$this->scopeConfig->getValue(self::XML_PATH_MAX_RETRIES));
        $retried = $this->dispatch(
            [Status::PENDING, Status::GENERATED],
            self::STALE_PROCESS_MINUTES,
            $limit,
            fn (int $id) => $this->publisher->publishProcess($id),
            $maxRetries
        );

        if ($refreshed || $retried) {
            $this->logger->info(
                sprintf('DigitalSignature reconcile: %d refresh, %d retry accodati', $refreshed, $retried)
            );
        }
    }

    /**
     * @param string[] $statuses
     */
    private function dispatch(
        array $statuses,
        int $staleMinutes,
        int $limit,
        callable $publish,
        ?int $maxRetries = null
    ): int {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status', ['in' => $statuses]);
        $collection->addFieldToFilter('is_active', 1);
        $collection->addFieldToFilter(
            'updated_at',
            ['lt' => date('Y-m-d H:i:s', time() - $staleMinutes * 60)]
        );
        if ($maxRetries !== null) {
            $collection->addFieldToFilter('retry_count', ['lt' => $maxRetries]);
        }
        $collection->setPageSize($limit);
        $collection->setOrder('updated_at', 'ASC');

        $count = 0;
        foreach ($collection as $document) {
            $publish((int)$document->getId());
            $count++;
        }

        return $count;
    }
}
