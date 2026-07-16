<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Service;

use MageOS\DigitalSignature\Api\Data\DocumentInterface;
use MageOS\DigitalSignature\Api\DocumentRepositoryInterface;
use MageOS\DigitalSignature\Model\Document\Status;
use MageOS\DigitalSignature\Model\DocumentFactory;
use MageOS\DigitalSignature\Model\Queue\Publisher;
use MageOS\DigitalSignature\Model\ResourceModel\Template as TemplateResource;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Al verificarsi di un trigger (place ordine, fattura, manuale) individua i
 * template applicabili all'ordine e crea i documenti firma, accodandone
 * l'elaborazione. L'enforcement della scelta cliente è qui, server-side.
 */
class TriggerHandler
{
    private const XML_PATH_ENABLED = 'digital_signature/general/enabled';
    private const XML_PATH_DEFAULT_TRIGGER = 'digital_signature/general/default_trigger';
    private const XML_PATH_PROVIDER = 'digital_signature/general/provider';

    /** Campo (futuro: da quote) con cui il cliente ha richiesto la firma */
    private const ORDER_FLAG_REQUESTED = 'digitalsignature_requested';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly TemplateResource $templateResource,
        private readonly DocumentFactory $documentFactory,
        private readonly DocumentRepositoryInterface $documentRepository,
        private readonly Publisher $publisher,
        private readonly LoggerInterface $logger,
        private readonly ProductionModeGuard $productionModeGuard
    ) {
    }

    public function handle(OrderInterface $order, string $triggerCode): void
    {
        $storeId = (int)$order->getStoreId();
        if (!$this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId)) {
            return;
        }
        $configuredProviderCode = (string)$this->scopeConfig->getValue(
            self::XML_PATH_PROVIDER,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        if ($configuredProviderCode === '') {
            return;
        }
        $providerCode = $this->productionModeGuard->resolveProviderCode($configuredProviderCode, $storeId);
        if ($providerCode !== $configuredProviderCode) {
            $this->logger->warning(sprintf(
                'DigitalSignature: modalità produzione non confermata (store %d): provider forzato a "%s" invece di "%s".',
                $storeId,
                $providerCode,
                $configuredProviderCode
            ));
        }
        $requestedByCustomer = (bool)$order->getData(self::ORDER_FLAG_REQUESTED);

        // Documento di carrello/ordine
        foreach ($this->templateResource->getActiveCartTemplateRows() as $row) {
            $resolved = $this->resolveTrigger(null, $row['trigger_code'] ?? null, $storeId);
            if ($resolved !== $triggerCode
                || !$this->passesCustomerChoice((bool)($row['is_required'] ?? false), $requestedByCustomer)
            ) {
                continue;
            }
            $this->createDocument(
                $order,
                DocumentInterface::ITEM_ID_CART_SCOPE,
                (int)$row['template_id'],
                $triggerCode,
                $providerCode
            );
        }

        // Documenti di prodotto (uno per riga ordine)
        $itemsByProduct = [];
        foreach ($order->getItems() as $item) {
            if ($item->getParentItemId()) {
                continue; // figli di configurabili/bundle: vale la riga padre
            }
            $itemsByProduct[(int)$item->getProductId()][] = (int)$item->getItemId();
        }
        $assignments = $this->templateResource->getProductAssignmentRows(array_keys($itemsByProduct));
        foreach ($assignments as $row) {
            $resolved = $this->resolveTrigger(
                $row['trigger_code'] ?? null,
                $row['template_trigger_code'] ?? null,
                $storeId
            );
            if ($resolved !== $triggerCode
                || !$this->passesCustomerChoice((bool)($row['is_required'] ?? false), $requestedByCustomer)
            ) {
                continue;
            }
            foreach ($itemsByProduct[(int)$row['product_id']] ?? [] as $orderItemId) {
                $this->createDocument($order, $orderItemId, (int)$row['template_id'], $triggerCode, $providerCode);
            }
        }
    }

    /**
     * Catena di risoluzione: override prodotto → trigger template → default globale.
     */
    private function resolveTrigger(?string $productOverride, ?string $templateTrigger, int $storeId): string
    {
        if ($productOverride !== null && $productOverride !== '') {
            return $productOverride;
        }
        if ($templateTrigger !== null && $templateTrigger !== '') {
            return $templateTrigger;
        }

        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_DEFAULT_TRIGGER,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Enforcement server-side: i template obbligatori generano sempre,
     * i facoltativi solo se il cliente ha richiesto la firma.
     */
    private function passesCustomerChoice(bool $isRequired, bool $requestedByCustomer): bool
    {
        return $isRequired || $requestedByCustomer;
    }

    private function createDocument(
        OrderInterface $order,
        int $orderItemId,
        int $templateId,
        string $triggerCode,
        string $providerCode
    ): void {
        $document = $this->documentFactory->create();
        $document->setOrderId((int)$order->getEntityId());
        $document->setOrderItemId($orderItemId);
        $document->setTemplateId($templateId);
        $document->setStoreId((int)$order->getStoreId());
        $document->setProviderCode($providerCode);
        $document->setStatus(Status::PENDING);
        $document->setIsActive(true);
        $document->setTriggerCode($triggerCode);
        $document->setSignerEmail((string)$order->getCustomerEmail());
        $document->setSignerPhone((string)($order->getBillingAddress()?->getTelephone() ?? ''));

        try {
            $this->documentRepository->save($document);
        } catch (\Exception $e) {
            // Indice univoco: documento attivo già esistente per la combinazione → skip
            if ($e instanceof AlreadyExistsException || $e->getPrevious() instanceof AlreadyExistsException
                || str_contains($e->getMessage(), 'Unique constraint')
                || str_contains($e->getMessage(), 'Duplicate entry')
            ) {
                return;
            }
            $this->logger->error('DigitalSignature: errore creazione documento: ' . $e->getMessage());

            return;
        }

        $this->documentRepository->addLog(
            $document,
            'status_change',
            null,
            Status::PENDING,
            sprintf('Documento creato dal trigger "%s"', $triggerCode)
        );
        $this->publisher->publishProcess((int)$document->getDocumentId());
    }
}
