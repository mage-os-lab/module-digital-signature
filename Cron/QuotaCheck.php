<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Cron;

use MageOS\DigitalSignature\Model\Notification\Notifier;
use MageOS\DigitalSignature\Model\Provider\ProviderConfig;
use MageOS\DigitalSignature\Model\Quota\Calculator;
use Magento\AdminNotification\Model\InboxFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

class QuotaCheck
{
    private const XML_PATH_ADMIN_RECIPIENT = 'digital_signature/notifications/admin_recipient';
    private const XML_PATH_GENERAL_EMAIL = 'trans_email/ident_general/email';

    public function __construct(
        private readonly Calculator $calculator,
        private readonly Notifier $notifier,
        private readonly ProviderConfig $providerConfig,
        private readonly InboxFactory $inboxFactory,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $providers = ['wssign', 'docusign', 'adobesign', 'dummy'];

        foreach ($providers as $providerCode) {
            try {
                $status = $this->calculator->calculate($providerCode);
                if (!$status->isQuotaEnabled()) {
                    continue;
                }

                if ($status->isWarningThreshold() || $status->isExhausted()) {
                    $this->handleQuotaAlert($status);
                }
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('DigitalSignature: quota check failed for provider %s: %s', $providerCode, $e->getMessage()));
            }
        }
    }

    private function handleQuotaAlert(\MageOS\DigitalSignature\Api\Data\QuotaStatusInterface $status): void
    {
        $providerCode = $status->getProviderCode();
        $message = sprintf(
            'Digital Signature provider "%s" quota alert: %s',
            strtoupper($providerCode),
            $status->getFormattedMessage()
        );

        // 1. Add admin notification inbox entry
        try {
            $inbox = $this->inboxFactory->create();
            $inbox->addNotice(
                __('Signature Provider Quota Warning: %1', strtoupper($providerCode)),
                $message
            );
        } catch (\Throwable $e) {
            $this->logger->warning('DigitalSignature: failed to add admin notification inbox entry: ' . $e->getMessage());
        }

        // 2. Send email notification
        $recipient = (string)($this->providerConfig->get($providerCode, 'notification_email')
            ?: $this->scopeConfig->getValue(self::XML_PATH_ADMIN_RECIPIENT, ScopeInterface::SCOPE_STORE)
            ?: $this->scopeConfig->getValue(self::XML_PATH_GENERAL_EMAIL, ScopeInterface::SCOPE_STORE));

        if ($recipient !== '') {
            $this->notifier->sendQuotaAlertEmail($status, $recipient);
        }
    }
}
