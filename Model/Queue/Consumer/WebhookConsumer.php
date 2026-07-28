<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Model\Queue\Consumer;

use MageOS\DigitalSignature\Api\DocumentRepositoryInterface;
use MageOS\DigitalSignature\Model\Notification\Notifier;
use MageOS\DigitalSignature\Model\ResourceModel\WebhookDelivery as WebhookDeliveryResource;
use MageOS\DigitalSignature\Model\ResourceModel\WebhookSubscription as WebhookSubscriptionResource;
use MageOS\DigitalSignature\Model\Webhook\BackoffCalculator;
use MageOS\DigitalSignature\Model\Webhook\Client;
use MageOS\DigitalSignature\Model\Webhook\SignatureSigner;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

/**
 * Consumer for the digitalsignature.webhook.dispatch topic: delivers a
 * webhook_delivery row, re-signing each time with the subscription's current
 * secret (avoids signatures with an already-rotated secret). Errors: retry
 * with backoff via the next_attempt_at column, the reconciliation cron
 * (Task 17) re-queues; exhausted attempts (or permanent error, e.g. 404/410)
 * → "failed" status + admin notification. The atomic claim on the row avoids
 * double POSTs when multiple consumers run in parallel.
 */
class WebhookConsumer
{
    private const XML_PATH_MAX_ATTEMPTS = 'digital_signature/webhooks/max_attempts';

    public function __construct(
        private readonly WebhookDeliveryResource $deliveryResource,
        private readonly WebhookSubscriptionResource $subscriptionResource,
        private readonly Client $client,
        private readonly SignatureSigner $signer,
        private readonly EncryptorInterface $encryptor,
        private readonly BackoffCalculator $backoffCalculator,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Notifier $notifier,
        private readonly DocumentRepositoryInterface $documentRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    public function process(string $deliveryId): void
    {
        $id = (int)$deliveryId;
        $row = $this->deliveryResource->getById($id);
        if ($row === null || $row['status'] !== WebhookDeliveryResource::STATUS_PENDING) {
            return;
        }
        $subscription = $this->subscriptionResource->getRowById((int)$row['subscription_id']);
        if ($subscription === null || !(bool)$subscription['enabled']) {
            $this->deliveryResource->markFailed(
                $id,
                (int)$row['attempts'],
                'Sottoscrizione rimossa o disabilitata.'
            );

            return;
        }

        // Atomic claim: if another consumer has already taken the row, exit.
        if (!$this->deliveryResource->claim($id)) {
            return;
        }

        try {
            $secret = $this->encryptor->decrypt((string)$subscription['secret']);
            $signature = $this->signer->sign((string)$row['payload'], $secret);
            $result = $this->client->deliver(
                (string)$subscription['target_url'],
                (string)$row['payload'],
                $signature
            );

            if ($result->isSuccess()) {
                $this->deliveryResource->markDelivered($id);

                return;
            }

            $this->handleFailure(
                $id,
                $row,
                $subscription,
                (string)$result->getError(),
                $result->isRetryable()
            );
        } catch (\Throwable $e) {
            // Without this catch the row would remain "in processing" forever
            // and the cron would re-queue it infinitely without incrementing attempts.
            $this->handleFailure($id, $row, $subscription, 'Errore interno: ' . $e->getMessage(), true);
        }
    }

    /**
     * Handles a delivery failure: increments the attempts and decides between
     * retry with backoff and final failure. A non-retryable error
     * (e.g. 404/410 from the receiver) closes immediately, without burning attempts.
     *
     * @param int $deliveryId
     * @param array<string, mixed> $row
     * @param array<string, mixed> $subscription
     * @param string $error
     * @param bool $retryable
     * @return void
     */
    private function handleFailure(
        int $deliveryId,
        array $row,
        array $subscription,
        string $error,
        bool $retryable
    ): void {
        $attempts = (int)$row['attempts'] + 1;
        $maxAttempts = max(1, (int)$this->scopeConfig->getValue(self::XML_PATH_MAX_ATTEMPTS));

        if (!$retryable || $attempts >= $maxAttempts) {
            $this->deliveryResource->markFailed($deliveryId, $attempts, $error);
            $this->notifyFailure((int)$row['document_id'], (string)$subscription['target_url'], $error);
            $this->logger->error(sprintf(
                'DigitalSignature: consegna webhook %d fallita definitivamente verso %s: %s',
                $deliveryId,
                (string)$subscription['target_url'],
                $error
            ));

            return;
        }

        $delayMinutes = $this->backoffCalculator->nextAttemptDelayMinutes($attempts);
        $nextAttemptAt = (new \DateTime('+' . $delayMinutes . ' minutes'))->format('Y-m-d H:i:s');
        $this->deliveryResource->scheduleRetry($deliveryId, $attempts, $error, $nextAttemptAt);
        $this->logger->warning(sprintf(
            'DigitalSignature: retry consegna webhook %d (tentativo %d): %s',
            $deliveryId,
            $attempts,
            $error
        ));
    }

    private function notifyFailure(int $documentId, string $targetUrl, string $error): void
    {
        try {
            $document = $this->documentRepository->getById($documentId);
        } catch (NoSuchEntityException) {
            return;
        }
        $this->notifier->notifyWebhookFailure($document, $targetUrl, $error);
    }
}
