<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Service;

use MageOS\DigitalSignature\Api\Data\DocumentInterface;
use MageOS\DigitalSignature\Api\DocumentRepositoryInterface;
use MageOS\DigitalSignature\Api\SignProviderPoolInterface;
use MageOS\DigitalSignature\Model\Document\Status;
use MageOS\DigitalSignature\Model\DocumentFactory;
use MageOS\DigitalSignature\Model\Queue\Publisher;
use MageOS\DigitalSignature\Model\Template\Source\Trigger;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Manual backend operations on signature documents: "manual" generation
 * (MANUAL trigger) and regeneration/resend of an active document
 * (archives the previous one and queues a new one).
 */
class DocumentManager
{
    public function __construct(
        private readonly DocumentRepositoryInterface $documentRepository,
        private readonly DocumentFactory $documentFactory,
        private readonly Publisher $publisher,
        private readonly TriggerHandler $triggerHandler,
        private readonly SignProviderPoolInterface $providerPool,
        private readonly ProductionModeGuard $productionModeGuard
    ) {
    }

    /**
     * Generates the template documents with "manual" trigger for the order.
     * The actual processing (PDF + sending to the provider) happens in the queue.
     */
    public function generateManual(OrderInterface $order): void
    {
        $this->triggerHandler->handle($order, Trigger::MANUAL);
    }

    /**
     * Regenerates/resends: cancels the active document (archived) and creates
     * a new one for the same combination, re-queuing it.
     *
     * @return int id of the new document
     * @throws LocalizedException
     */
    public function regenerate(int $documentId): int
    {
        $old = $this->documentRepository->getById($documentId);
        if (!$old->getIsActive()) {
            throw new LocalizedException(
                __('Document #%1 is not active: use the active one to regenerate.', $documentId)
            );
        }

        $previousStatus = $old->getStatus();
        $old->setIsActive(false);
        $old->setStatus(Status::CANCELED);
        $this->documentRepository->save($old);
        $this->documentRepository->addLog(
            $old,
            'status_change',
            $previousStatus,
            Status::CANCELED,
            (string)__('Document canceled for manual regeneration.')
        );
        $this->cancelAtProvider($old);

        $new = $this->documentFactory->create();
        $new->setOrderId($old->getOrderId());
        $new->setOrderItemId($old->getOrderItemId());
        $new->setTemplateId($old->getTemplateId());
        $new->setStoreId($old->getStoreId());
        $new->setProviderCode(
            $this->productionModeGuard->resolveProviderCode((string)$old->getProviderCode(), (int)$old->getStoreId())
        );
        $new->setStatus(Status::PENDING);
        $new->setIsActive(true);
        $new->setTriggerCode($old->getTriggerCode());
        $new->setSignerEmail($old->getSignerEmail());
        $new->setSignerPhone($old->getSignerPhone());
        $this->documentRepository->save($new);

        $newId = (int)$new->getDocumentId();
        $this->documentRepository->addLog(
            $new,
            'status_change',
            null,
            Status::PENDING,
            (string)__('Document regenerated (replaces #%1).', $documentId)
        );
        $this->publisher->publishProcess($newId);

        return $newId;
    }

    /**
     * Cancels the signature process on the provider side as well (design
     * decision: the replaced document must not remain signable). Best-effort: a
     * provider error does not block the regeneration, but is logged.
     */
    private function cancelAtProvider(DocumentInterface $old): void
    {
        if ($old->getProviderProcessId() === null) {
            return;
        }
        try {
            $this->providerPool->get($old->getProviderCode())->cancel($old);
        } catch (\Exception $e) {
            $this->documentRepository->addLog(
                $old,
                'error',
                null,
                null,
                'Provider-side cancellation failed: ' . $e->getMessage()
            );
        }
    }
}
