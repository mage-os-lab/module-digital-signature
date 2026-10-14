<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Service;

use MageOS\DigitalSignature\Model\Service\AuditTrailComplianceGate;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AuditTrailComplianceGateTest extends TestCase
{
    private ScopeConfigInterface&MockObject $scopeConfig;
    private AuditTrailComplianceGate $gate;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->gate = new AuditTrailComplianceGate($this->scopeConfig);
    }

    public function testNotConfiguredWhenChoiceIsEmpty(): void
    {
        $this->configure(choice: '');

        self::assertFalse($this->gate->isConfigured(1));
    }

    public function testConfiguredWhenMerchantExplicitlyOptsOut(): void
    {
        $this->configure(choice: '0');

        self::assertTrue($this->gate->isConfigured(1));
    }

    public function testNotConfiguredWhenOptedInButPolicyNotAcknowledged(): void
    {
        $this->configure(choice: '1', ack: false, retentionDays: '3650');

        self::assertFalse($this->gate->isConfigured(1));
    }

    public function testNotConfiguredWhenOptedInButRetentionDaysMissing(): void
    {
        $this->configure(choice: '1', ack: true, retentionDays: '');

        self::assertFalse($this->gate->isConfigured(1));
    }

    public function testNotConfiguredWhenOptedInButRetentionDaysIsZero(): void
    {
        $this->configure(choice: '1', ack: true, retentionDays: '0');

        self::assertFalse($this->gate->isConfigured(1));
    }

    public function testConfiguredWhenOptedInWithAckAndRetentionDays(): void
    {
        $this->configure(choice: '1', ack: true, retentionDays: '3650');

        self::assertTrue($this->gate->isConfigured(1));
    }

    public function testReadsGivenStoreScope(): void
    {
        $this->scopeConfig->expects(self::once())
            ->method('getValue')
            ->with('digital_signature/audit_trail/data_collection', ScopeInterface::SCOPE_STORE, 7)
            ->willReturn('0');

        $this->gate->isConfigured(7);
    }

    public function testDataCollectionEnabledWhenChoiceIsYes(): void
    {
        $this->configure(choice: '1', ack: true, retentionDays: '3650');

        self::assertTrue($this->gate->isDataCollectionEnabled(1));
    }

    public function testDataCollectionDisabledWithoutPolicyAcknowledgement(): void
    {
        // The gate blocks new documents in this state; documents already in
        // flight must not start collecting personal data either.
        $this->configure(choice: '1', ack: false, retentionDays: '3650');

        self::assertFalse($this->gate->isDataCollectionEnabled(1));
    }

    public function testDataCollectionDisabledWithoutRetentionPeriod(): void
    {
        $this->configure(choice: '1', ack: true, retentionDays: '0');

        self::assertFalse($this->gate->isDataCollectionEnabled(1));
    }

    public function testDataCollectionDisabledWhenChoiceIsNo(): void
    {
        $this->configure(choice: '0');

        self::assertFalse($this->gate->isDataCollectionEnabled(1));
    }

    public function testDataCollectionDisabledWhenChoiceIsUnset(): void
    {
        $this->configure(choice: '');

        self::assertFalse($this->gate->isDataCollectionEnabled(1));
    }

    private function configure(string $choice, bool $ack = false, string $retentionDays = ''): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['digital_signature/audit_trail/data_collection', ScopeInterface::SCOPE_STORE, 1, $choice],
            ['digital_signature/audit_trail/retention_days', ScopeInterface::SCOPE_STORE, 1, $retentionDays],
        ]);
        $this->scopeConfig->method('isSetFlag')
            ->with('digital_signature/audit_trail/policy_ack', ScopeInterface::SCOPE_STORE, 1)
            ->willReturn($ack);
    }
}
