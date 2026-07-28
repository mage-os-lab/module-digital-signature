<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Queue\Consumer;

use MageOS\DigitalSignature\Api\DocumentRepositoryInterface;
use MageOS\DigitalSignature\Model\Notification\Notifier;
use MageOS\DigitalSignature\Model\Queue\Consumer\WebhookConsumer;
use MageOS\DigitalSignature\Model\ResourceModel\WebhookDelivery as WebhookDeliveryResource;
use MageOS\DigitalSignature\Model\ResourceModel\WebhookSubscription as WebhookSubscriptionResource;
use MageOS\DigitalSignature\Model\Webhook\BackoffCalculator;
use MageOS\DigitalSignature\Model\Webhook\Client;
use MageOS\DigitalSignature\Model\Webhook\DeliveryResult;
use MageOS\DigitalSignature\Model\Webhook\SignatureSigner;
use MageOS\DigitalSignature\TestSupport\FakeDocument;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WebhookConsumerTest extends TestCase
{
    private WebhookDeliveryResource&MockObject $deliveryResource;
    private WebhookSubscriptionResource&MockObject $subscriptionResource;
    private Client&MockObject $client;
    private EncryptorInterface&MockObject $encryptor;
    private Notifier&MockObject $notifier;
    private WebhookConsumer $consumer;

    protected function setUp(): void
    {
        $this->deliveryResource = $this->createMock(WebhookDeliveryResource::class);
        $this->subscriptionResource = $this->createMock(WebhookSubscriptionResource::class);
        $this->client = $this->createMock(Client::class);
        $this->encryptor = $this->createMock(EncryptorInterface::class);
        $this->notifier = $this->createMock(Notifier::class);

        $signer = $this->createMock(SignatureSigner::class);
        $signer->method('sign')->willReturn('sha256=abc');

        $backoff = $this->createMock(BackoffCalculator::class);
        $backoff->method('nextAttemptDelayMinutes')->willReturn(5);

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(5);

        $documentRepository = $this->createMock(DocumentRepositoryInterface::class);
        $documentRepository->method('getById')->willReturn(new FakeDocument());

        $this->consumer = new WebhookConsumer(
            $this->deliveryResource,
            $this->subscriptionResource,
            $this->client,
            $signer,
            $this->encryptor,
            $backoff,
            $scopeConfig,
            $this->notifier,
            $documentRepository,
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function pendingRow(array $overrides = []): array
    {
        return $overrides + [
            'delivery_id' => 10,
            'subscription_id' => 3,
            'document_id' => 7,
            'attempts' => 0,
            'payload' => '{"delivery_id":10}',
            'status' => WebhookDeliveryResource::STATUS_PENDING,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriptionRow(): array
    {
        return [
            'subscription_id' => 3,
            'enabled' => 1,
            'secret' => 'enc',
            'target_url' => 'https://erp.example.com/hook',
        ];
    }

    public function testDeliversWhenClaimSucceeds(): void
    {
        $this->deliveryResource->method('getById')->willReturn($this->pendingRow());
        $this->subscriptionResource->method('getRowById')->willReturn($this->subscriptionRow());
        $this->encryptor->method('decrypt')->willReturn('secret');
        $this->deliveryResource->expects(self::once())->method('claim')->with(10)->willReturn(true);
        $this->client->method('deliver')->willReturn(DeliveryResult::success());
        $this->deliveryResource->expects(self::once())->method('markDelivered')->with(10);

        $this->consumer->process('10');
    }

    public function testSkipsDeliveryWhenClaimFails(): void
    {
        $this->deliveryResource->method('getById')->willReturn($this->pendingRow());
        $this->subscriptionResource->method('getRowById')->willReturn($this->subscriptionRow());
        $this->deliveryResource->method('claim')->willReturn(false);
        $this->client->expects(self::never())->method('deliver');
        $this->deliveryResource->expects(self::never())->method('markDelivered');
        $this->deliveryResource->expects(self::never())->method('scheduleRetry');

        $this->consumer->process('10');
    }

    public function testSchedulesRetryOnRetryableFailure(): void
    {
        $this->deliveryResource->method('getById')->willReturn($this->pendingRow());
        $this->subscriptionResource->method('getRowById')->willReturn($this->subscriptionRow());
        $this->encryptor->method('decrypt')->willReturn('secret');
        $this->deliveryResource->method('claim')->willReturn(true);
        $this->client->method('deliver')->willReturn(DeliveryResult::failure('HTTP 503', true));

        $this->deliveryResource->expects(self::once())->method('scheduleRetry')
            ->with(10, 1, 'HTTP 503', self::isType('string'));
        $this->deliveryResource->expects(self::never())->method('markFailed');

        $this->consumer->process('10');
    }

    public function testFailsImmediatelyOnPermanentError(): void
    {
        $this->deliveryResource->method('getById')->willReturn($this->pendingRow());
        $this->subscriptionResource->method('getRowById')->willReturn($this->subscriptionRow());
        $this->encryptor->method('decrypt')->willReturn('secret');
        $this->deliveryResource->method('claim')->willReturn(true);
        $this->client->method('deliver')->willReturn(DeliveryResult::failure('HTTP 404', false));

        $this->deliveryResource->expects(self::never())->method('scheduleRetry');
        $this->deliveryResource->expects(self::once())->method('markFailed')->with(10, 1, 'HTTP 404');
        $this->notifier->expects(self::once())->method('notifyWebhookFailure');

        $this->consumer->process('10');
    }

    public function testUnexpectedExceptionIsTreatedAsRetryableFailure(): void
    {
        $this->deliveryResource->method('getById')->willReturn($this->pendingRow());
        $this->subscriptionResource->method('getRowById')->willReturn($this->subscriptionRow());
        $this->deliveryResource->method('claim')->willReturn(true);
        $this->encryptor->method('decrypt')->willThrowException(new \RuntimeException('chiave non valida'));

        $this->deliveryResource->expects(self::once())->method('scheduleRetry')
            ->with(10, 1, self::stringContains('chiave non valida'), self::isType('string'));

        $this->consumer->process('10');
    }

    public function testFailsPermanentlyWhenAttemptsExhausted(): void
    {
        $this->deliveryResource->method('getById')->willReturn($this->pendingRow(['attempts' => 4]));
        $this->subscriptionResource->method('getRowById')->willReturn($this->subscriptionRow());
        $this->encryptor->method('decrypt')->willReturn('secret');
        $this->deliveryResource->method('claim')->willReturn(true);
        $this->client->method('deliver')->willReturn(DeliveryResult::failure('HTTP 500', true));

        $this->deliveryResource->expects(self::never())->method('scheduleRetry');
        $this->deliveryResource->expects(self::once())->method('markFailed')->with(10, 5, 'HTTP 500');

        $this->consumer->process('10');
    }

    public function testDoesNotClaimWhenSubscriptionDisabled(): void
    {
        $this->deliveryResource->method('getById')->willReturn($this->pendingRow());
        $this->subscriptionResource->method('getRowById')->willReturn(['enabled' => 0] + $this->subscriptionRow());
        $this->deliveryResource->expects(self::never())->method('claim');
        $this->deliveryResource->expects(self::once())->method('markFailed');

        $this->consumer->process('10');
    }

    public function testIgnoresRowNotPending(): void
    {
        $this->deliveryResource->method('getById')
            ->willReturn($this->pendingRow(['status' => WebhookDeliveryResource::STATUS_SENDING]));
        $this->deliveryResource->expects(self::never())->method('claim');
        $this->client->expects(self::never())->method('deliver');

        $this->consumer->process('10');
    }
}
