<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Notification;

use MageOS\DigitalSignature\Api\Data\DocumentInterface;
use MageOS\DigitalSignature\Model\Document\Status;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Framework\UrlInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Invio delle notifiche email legate al ciclo di vita del documento firma.
 *
 * Tutti i template sono renderizzati in area frontend (anche quelli all'admin):
 * questo evita problemi di area quando l'invio parte dai consumer della coda o
 * dal cron. Nessun invio deve mai interrompere l'elaborazione del documento:
 * le eccezioni vengono catturate e loggate.
 */
class Notifier
{
    private const XML_PATH_IDENTITY = 'digital_signature/notifications/identity';
    private const XML_PATH_ADMIN_RECIPIENT = 'digital_signature/notifications/admin_recipient';

    private const XML_PATH_CUSTOMER_READY_ENABLED = 'digital_signature/notifications/customer_ready_enabled';
    private const XML_PATH_CUSTOMER_READY_TEMPLATE = 'digital_signature/notifications/customer_ready_template';
    private const XML_PATH_CUSTOMER_SIGNED_ENABLED = 'digital_signature/notifications/customer_signed_enabled';
    private const XML_PATH_CUSTOMER_SIGNED_TEMPLATE = 'digital_signature/notifications/customer_signed_template';
    private const XML_PATH_CUSTOMER_OUTCOME_ENABLED = 'digital_signature/notifications/customer_outcome_enabled';
    private const XML_PATH_CUSTOMER_OUTCOME_TEMPLATE = 'digital_signature/notifications/customer_outcome_template';
    private const XML_PATH_ADMIN_ERROR_ENABLED = 'digital_signature/notifications/admin_error_enabled';
    private const XML_PATH_ADMIN_ERROR_TEMPLATE = 'digital_signature/notifications/admin_error_template';
    private const XML_PATH_ADMIN_OUTCOME_ENABLED = 'digital_signature/notifications/admin_outcome_enabled';
    private const XML_PATH_ADMIN_OUTCOME_TEMPLATE = 'digital_signature/notifications/admin_outcome_template';

    private const XML_PATH_CUSTOMER_REMINDER_ENABLED = 'digital_signature/reminders/customer_enabled';
    private const XML_PATH_CUSTOMER_REMINDER_TEMPLATE = 'digital_signature/reminders/customer_template';
    private const XML_PATH_ADMIN_ESCALATION_ENABLED = 'digital_signature/reminders/admin_enabled';
    private const XML_PATH_ADMIN_ESCALATION_TEMPLATE = 'digital_signature/reminders/admin_template';

    /** Fallback per l'indirizzo admin quando il campo dedicato è vuoto */
    private const XML_PATH_GENERAL_EMAIL = 'trans_email/ident_general/email';

    public function __construct(
        private readonly TransportBuilder $transportBuilder,
        private readonly StateInterface $inlineTranslation,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Documento pronto per la firma: avviso al cliente (opzionale, spesso il
     * provider invia già il proprio invito a firmare).
     */
    public function notifyDocumentReady(DocumentInterface $document): void
    {
        $storeId = $this->storeId($document);
        if (!$this->isEnabled(self::XML_PATH_CUSTOMER_READY_ENABLED, $storeId)) {
            return;
        }
        $this->sendToCustomer($document, self::XML_PATH_CUSTOMER_READY_TEMPLATE, $storeId);
    }

    /**
     * Documento firmato: il cliente trova il PDF nell'area "i miei ordini".
     */
    public function notifyDocumentSigned(DocumentInterface $document): void
    {
        $storeId = $this->storeId($document);
        if (!$this->isEnabled(self::XML_PATH_CUSTOMER_SIGNED_ENABLED, $storeId)) {
            return;
        }
        $this->sendToCustomer($document, self::XML_PATH_CUSTOMER_SIGNED_TEMPLATE, $storeId);
    }

    /**
     * Documento scaduto o rifiutato: avviso all'admin e, se abilitato, al cliente.
     */
    public function notifyDocumentOutcome(DocumentInterface $document): void
    {
        $storeId = $this->storeId($document);
        if ($this->isEnabled(self::XML_PATH_ADMIN_OUTCOME_ENABLED, $storeId)) {
            $this->sendToAdmin($document, self::XML_PATH_ADMIN_OUTCOME_TEMPLATE, $storeId);
        }
        if ($this->isEnabled(self::XML_PATH_CUSTOMER_OUTCOME_ENABLED, $storeId)) {
            $this->sendToCustomer($document, self::XML_PATH_CUSTOMER_OUTCOME_TEMPLATE, $storeId);
        }
    }

    /**
     * Errore definitivo nell'elaborazione: avviso all'admin per intervento manuale.
     */
    public function notifyDocumentError(DocumentInterface $document, string $message): void
    {
        $storeId = $this->storeId($document);
        if (!$this->isEnabled(self::XML_PATH_ADMIN_ERROR_ENABLED, $storeId)) {
            return;
        }
        $this->sendToAdmin($document, self::XML_PATH_ADMIN_ERROR_TEMPLATE, $storeId, $message);
    }

    /**
     * Sollecito al cliente per documento in firma.
     */
    public function notifyDocumentReminder(DocumentInterface $document): void
    {
        $storeId = $this->storeId($document);
        if (!$this->isEnabled(self::XML_PATH_CUSTOMER_REMINDER_ENABLED, $storeId)) {
            return;
        }
        $this->sendToCustomer($document, self::XML_PATH_CUSTOMER_REMINDER_TEMPLATE, $storeId);
    }

    /**
     * Escalation all'admin per documento fermo in firma.
     */
    public function notifyDocumentEscalation(DocumentInterface $document): void
    {
        $storeId = $this->storeId($document);
        if (!$this->isEnabled(self::XML_PATH_ADMIN_ESCALATION_ENABLED, $storeId)) {
            return;
        }
        $this->sendToAdmin($document, self::XML_PATH_ADMIN_ESCALATION_TEMPLATE, $storeId);
    }

    private function sendToCustomer(DocumentInterface $document, string $templatePath, int $storeId): void
    {
        $email = $document->getSignerEmail();
        if ($email === null) {
            return;
        }
        $vars = $this->buildVars($document, $storeId);
        $this->dispatch(
            $this->scopeConfig->getValue($templatePath, ScopeInterface::SCOPE_STORE, $storeId),
            $storeId,
            $vars,
            $email,
            $vars['customer_name']
        );
    }

    private function sendToAdmin(
        DocumentInterface $document,
        string $templatePath,
        int $storeId,
        string $errorMessage = ''
    ): void {
        $recipient = (string)$this->scopeConfig->getValue(
            self::XML_PATH_ADMIN_RECIPIENT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        if ($recipient === '') {
            $recipient = (string)$this->scopeConfig->getValue(
                self::XML_PATH_GENERAL_EMAIL,
                ScopeInterface::SCOPE_STORE,
                $storeId
            );
        }
        if ($recipient === '') {
            return;
        }
        $vars = $this->buildVars($document, $storeId);
        $vars['error_message'] = $errorMessage;
        $this->dispatch(
            $this->scopeConfig->getValue($templatePath, ScopeInterface::SCOPE_STORE, $storeId),
            $storeId,
            $vars,
            $recipient
        );
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function dispatch(
        ?string $templateId,
        int $storeId,
        array $vars,
        string $recipientEmail,
        string $recipientName = ''
    ): void {
        if ($templateId === null || $templateId === '') {
            return;
        }
        try {
            $this->inlineTranslation->suspend();
            $transport = $this->transportBuilder
                ->setTemplateIdentifier($templateId)
                ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $storeId])
                ->setTemplateVars($vars)
                ->setFromByScope(
                    $this->scopeConfig->getValue(
                        self::XML_PATH_IDENTITY,
                        ScopeInterface::SCOPE_STORE,
                        $storeId
                    ) ?: 'general',
                    $storeId
                )
                ->addTo($recipientEmail, $recipientName)
                ->getTransport();
            $transport->sendMessage();
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf('DigitalSignature: invio email "%s" fallito: %s', (string)$templateId, $e->getMessage())
            );
        } finally {
            $this->inlineTranslation->resume();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildVars(DocumentInterface $document, int $storeId): array
    {
        $orderIncrementId = '';
        $customerName = '';
        $orderUrl = '';
        $orderId = $document->getOrderId();
        try {
            $order = $this->orderRepository->get($orderId);
            $orderIncrementId = (string)$order->getIncrementId();
            $customerName = trim((string)$order->getCustomerFirstname() . ' ' . (string)$order->getCustomerLastname());
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('DigitalSignature: ordine %d non caricabile per email: %s', $orderId, $e->getMessage())
            );
        }

        try {
            $baseUrl = $this->storeManager->getStore($storeId)->getBaseUrl(UrlInterface::URL_TYPE_LINK);
            $orderUrl = $baseUrl . 'sales/order/view/order_id/' . $orderId . '/';
        } catch (\Throwable $e) {
            $this->logger->warning('DigitalSignature: base URL store non disponibile per email: ' . $e->getMessage());
        }

        return [
            'document_id' => (int)$document->getDocumentId(),
            'order_id' => $orderId,
            'order_increment_id' => $orderIncrementId,
            'customer_name' => $customerName,
            'signer_email' => (string)($document->getSignerEmail() ?? ''),
            'provider_code' => $document->getProviderCode(),
            'status' => $document->getStatus(),
            'status_label' => (string)(Status::getLabels()[$document->getStatus()] ?? $document->getStatus()),
            'order_url' => $orderUrl,
            'error_message' => '',
        ];
    }

    private function isEnabled(string $path, int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    private function storeId(DocumentInterface $document): int
    {
        return (int)($document->getStoreId() ?? 0);
    }
}
