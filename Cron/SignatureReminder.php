<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Cron;

use MageOS\DigitalSignature\Api\Data\DocumentInterface;
use MageOS\DigitalSignature\Api\DocumentRepositoryInterface;
use MageOS\DigitalSignature\Model\Document\Status;
use MageOS\DigitalSignature\Model\Notification\Notifier;
use MageOS\DigitalSignature\Model\Reminder\EligibilityCalculator;
use MageOS\DigitalSignature\Model\ResourceModel\Document\CollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class SignatureReminder
{
    private const XML_PATH_CUSTOMER_ENABLED = 'digital_signature/reminders/customer_enabled';
    private const XML_PATH_CUSTOMER_THRESHOLD_DAYS = 'digital_signature/reminders/customer_threshold_days';
    private const XML_PATH_CUSTOMER_INTERVAL_DAYS = 'digital_signature/reminders/customer_interval_days';
    private const XML_PATH_CUSTOMER_MAX_REMINDERS = 'digital_signature/reminders/customer_max_reminders';
    private const XML_PATH_ADMIN_ENABLED = 'digital_signature/reminders/admin_enabled';
    private const XML_PATH_ADMIN_THRESHOLD_DAYS = 'digital_signature/reminders/admin_threshold_days';
    private const XML_PATH_BATCH_LIMIT = 'digital_signature/reminders/batch_limit';

    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly DocumentRepositoryInterface $documentRepository,
        private readonly EligibilityCalculator $eligibilityCalculator,
        private readonly Notifier $notifier,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function execute(): void
    {
        $limit = max(1, (int)$this->scopeConfig->getValue(self::XML_PATH_BATCH_LIMIT));

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status', Status::SENT);
        $collection->addFieldToFilter('is_active', 1);
        $collection->setPageSize($limit);
        $collection->setOrder('updated_at', 'ASC');

        $now = new \DateTime('now', new \DateTimeZone('UTC'));

        $adminEnabled = $this->scopeConfig->isSetFlag(self::XML_PATH_ADMIN_ENABLED);
        $adminThresholdDays = (int)$this->scopeConfig->getValue(self::XML_PATH_ADMIN_THRESHOLD_DAYS);

        foreach ($collection as $document) {
            $storeId = (int)$document->getStoreId();

            $customerEnabled = $this->scopeConfig->isSetFlag(
                self::XML_PATH_CUSTOMER_ENABLED,
                ScopeInterface::SCOPE_STORE,
                $storeId
            );
            $customerThresholdDays = (int)$this->scopeConfig->getValue(
                self::XML_PATH_CUSTOMER_THRESHOLD_DAYS,
                ScopeInterface::SCOPE_STORE,
                $storeId
            );
            $customerIntervalDays = (int)$this->scopeConfig->getValue(
                self::XML_PATH_CUSTOMER_INTERVAL_DAYS,
                ScopeInterface::SCOPE_STORE,
                $storeId
            );
            $customerMaxReminders = (int)$this->scopeConfig->getValue(
                self::XML_PATH_CUSTOMER_MAX_REMINDERS,
                ScopeInterface::SCOPE_STORE,
                $storeId
            );

            $shouldSave = false;

            // 1. Check Customer Reminder
            if ($this->eligibilityCalculator->shouldSendCustomerReminder(
                $document,
                $now,
                $customerEnabled,
                $customerThresholdDays,
                $customerIntervalDays,
                $customerMaxReminders
            )) {
                $this->notifier->notifyDocumentReminder($document);

                $document->setReminderCount($document->getReminderCount() + 1);
                $document->setLastReminderAt($now->format('Y-m-d H:i:s'));
                $shouldSave = true;

                $this->documentRepository->addLog(
                    $document,
                    'reminder_sent',
                    Status::SENT,
                    Status::SENT,
                    (string)__('Inviato sollecito cliente (sollecito %1 di %2).', $document->getReminderCount(), $customerMaxReminders)
                );
            }

            // 2. Check Admin Escalation
            if ($this->eligibilityCalculator->shouldSendAdminEscalation(
                $document,
                $now,
                $adminEnabled,
                $adminThresholdDays
            )) {
                $this->notifier->notifyDocumentEscalation($document);

                $document->setEscalationSentAt($now->format('Y-m-d H:i:s'));
                $shouldSave = true;

                $this->documentRepository->addLog(
                    $document,
                    'escalation_sent',
                    Status::SENT,
                    Status::SENT,
                    (string)__('Inviata email di escalation all\'admin per documento in attesa di firma.')
                );
            }

            if ($shouldSave) {
                $this->documentRepository->save($document);
            }
        }
    }
}
