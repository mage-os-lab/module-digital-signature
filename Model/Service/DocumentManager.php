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
 * Operazioni manuali da backend sui documenti firma: generazione "manuale"
 * (trigger MANUAL) e rigenerazione/reinvio di un documento attivo
 * (storicizza il precedente e ne accoda uno nuovo).
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
     * Genera i documenti dei template con trigger "manuale" per l'ordine.
     * L'elaborazione vera (PDF + invio al provider) avviene in coda.
     */
    public function generateManual(OrderInterface $order): void
    {
        $this->triggerHandler->handle($order, Trigger::MANUAL);
    }

    /**
     * Rigenera/reinvia: annulla il documento attivo (storicizzato) e ne crea
     * uno nuovo per la stessa combinazione, riaccodandolo.
     *
     * @return int id del nuovo documento
     * @throws LocalizedException
     */
    public function regenerate(int $documentId): int
    {
        $old = $this->documentRepository->getById($documentId);
        if (!$old->getIsActive()) {
            throw new LocalizedException(
                __('Il documento #%1 non è attivo: usa quello attivo per rigenerare.', $documentId)
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
            (string)__('Documento annullato per rigenerazione manuale.')
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
            (string)__('Documento rigenerato (sostituisce #%1).', $documentId)
        );
        $this->publisher->publishProcess($newId);

        return $newId;
    }

    /**
     * Annulla il processo di firma anche lato provider (decisione di analisi:
     * il documento sostituito non deve restare firmabile). Best-effort: un
     * errore del provider non blocca la rigenerazione, ma resta a log.
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
                'Annullamento lato provider non riuscito: ' . $e->getMessage()
            );
        }
    }
}
