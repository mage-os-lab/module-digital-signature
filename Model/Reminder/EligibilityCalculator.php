<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Reminder;

use MageOS\DigitalSignature\Api\Data\DocumentInterface;
use MageOS\DigitalSignature\Model\Document\Status;

class EligibilityCalculator
{
    /**
     * Determines if the document is eligible for a customer reminder email.
     */
    public function shouldSendCustomerReminder(
        DocumentInterface $document,
        \DateTimeInterface $now,
        bool $enabled,
        int $thresholdDays,
        int $intervalDays,
        int $maxReminders
    ): bool {
        if (!$enabled) {
            return false;
        }

        if ($document->getStatus() !== Status::SENT) {
            return false;
        }

        if (!$document->getIsActive()) {
            return false;
        }

        if ($document->getReminderCount() >= $maxReminders) {
            return false;
        }

        $updatedAtStr = $document->getUpdatedAt();
        if (empty($updatedAtStr)) {
            return false;
        }

        $updatedAt = new \DateTime($updatedAtStr, new \DateTimeZone('UTC'));
        $diff = $updatedAt->diff($now);
        $daysSinceSent = (int)$diff->format('%r%a');

        if ($daysSinceSent < $thresholdDays) {
            return false;
        }

        $lastReminderStr = $document->getLastReminderAt();
        if ($lastReminderStr !== null) {
            $lastReminder = new \DateTime($lastReminderStr, new \DateTimeZone('UTC'));
            $diffLast = $lastReminder->diff($now);
            $daysSinceLastReminder = (int)$diffLast->format('%r%a');
            if ($daysSinceLastReminder < $intervalDays) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determines if the document is eligible for an admin escalation email.
     */
    public function shouldSendAdminEscalation(
        DocumentInterface $document,
        \DateTimeInterface $now,
        bool $enabled,
        int $thresholdDays
    ): bool {
        if (!$enabled) {
            return false;
        }

        if ($document->getStatus() !== Status::SENT) {
            return false;
        }

        if (!$document->getIsActive()) {
            return false;
        }

        if ($document->getEscalationSentAt() !== null) {
            return false;
        }

        $updatedAtStr = $document->getUpdatedAt();
        if (empty($updatedAtStr)) {
            return false;
        }

        $updatedAt = new \DateTime($updatedAtStr, new \DateTimeZone('UTC'));
        $diff = $updatedAt->diff($now);
        $daysSinceSent = (int)$diff->format('%r%a');

        if ($daysSinceSent < $thresholdDays) {
            return false;
        }

        return true;
    }
}
