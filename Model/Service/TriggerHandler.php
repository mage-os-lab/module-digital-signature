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
 * When a trigger occurs (order placement, invoice, manual) identifies the
 * templates applicable to the order and creates the signature documents,
 * queuing their processing. Enforcement of the customer's choice is here, server-side.
 */
class TriggerHandler
{
    private const XML_PATH_ENABLED = 'digital_signature/general/enabled';
    private const XML_PATH_DEFAULT_TRIGGER = 'digital_signature/general/default_trigger';
    private const XML_PATH_PROVIDER = 'digital_signature/general/provider';

    /** Field (future: from quote) with which the customer requested the signature */
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
                'DigitalSignature: unconfirmed production mode (store %d): provider forced to "%s" instead of "%s".',
                $storeId,
                $providerCode,
                $configuredProviderCode
            ));
        }
        $requestedByCustomer = (bool)$order->getData(self::ORDER_FLAG_REQUESTED);

        // Cart/order document
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

        // Product documents (one per order line)
        $itemsByProduct = [];
        foreach ($order->getItems() as $item) {
            if ($item->getParentItemId()) {
                continue; // children of configurables/bundles: the parent line applies
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
     * Resolution chain: product override → template trigger → global default.
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
     * Server-side enforcement: mandatory templates always generate,
     * optional ones only if the customer has requested the signature.
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
            // Unique index: active document already exists for the combination → skip
            if ($e instanceof AlreadyExistsException || $e->getPrevious() instanceof AlreadyExistsException
                || str_contains($e->getMessage(), 'Unique constraint')
                || str_contains($e->getMessage(), 'Duplicate entry')
            ) {
                return;
            }
            $this->logger->error('DigitalSignature: error creating document: ' . $e->getMessage());

            return;
        }

        $this->documentRepository->addLog(
            $document,
            'status_change',
            null,
            Status::PENDING,
            sprintf('Document created by trigger "%s"', $triggerCode)
        );
        $this->publisher->publishProcess((int)$document->getDocumentId());
    }
}
