<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Notification;

use MageOS\DigitalSignature\Model\Notification\AuditTrailComplianceMessage;
use MageOS\DigitalSignature\Model\Service\AuditTrailComplianceGate;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Notification\MessageInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AuditTrailComplianceMessageTest extends TestCase
{
    private ScopeConfigInterface&MockObject $scopeConfig;
    private StoreManagerInterface&MockObject $storeManager;
    private AuditTrailComplianceGate&MockObject $gate;
    private AuditTrailComplianceMessage $message;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->gate = $this->createMock(AuditTrailComplianceGate::class);
        $this->message = new AuditTrailComplianceMessage($this->scopeConfig, $this->storeManager, $this->gate);
    }

    public function testNotDisplayedWhenModuleDisabledEverywhere(): void
    {
        $this->storeManager->method('getStores')->willReturn([$this->buildStore(1)]);
        $this->scopeConfig->method('isSetFlag')
            ->with('digital_signature/general/enabled', ScopeInterface::SCOPE_STORE, 1)
            ->willReturn(false);
        $this->gate->expects(self::never())->method('isConfigured');

        self::assertFalse($this->message->isDisplayed());
    }

    public function testNotDisplayedWhenEnabledStoreIsFullyConfigured(): void
    {
        $this->storeManager->method('getStores')->willReturn([$this->buildStore(1)]);
        $this->scopeConfig->method('isSetFlag')->willReturn(true);
        $this->gate->method('isConfigured')->with(1)->willReturn(true);

        self::assertFalse($this->message->isDisplayed());
    }

    public function testDisplayedWhenAnEnabledStoreIsNotConfigured(): void
    {
        $this->storeManager->method('getStores')->willReturn([$this->buildStore(1), $this->buildStore(2)]);
        $this->scopeConfig->method('isSetFlag')->willReturnMap([
            ['digital_signature/general/enabled', ScopeInterface::SCOPE_STORE, 1, true],
            ['digital_signature/general/enabled', ScopeInterface::SCOPE_STORE, 2, true],
        ]);
        $this->gate->method('isConfigured')->willReturnMap([
            [1, false],
            [2, true],
        ]);

        self::assertTrue($this->message->isDisplayed());
    }

    public function testSeverityIsCritical(): void
    {
        self::assertSame(MessageInterface::SEVERITY_CRITICAL, $this->message->getSeverity());
    }

    public function testIdentityIsStable(): void
    {
        self::assertSame(
            'mageos_digitalsignature_audit_trail_compliance',
            $this->message->getIdentity()
        );
    }

    private function buildStore(int $id): StoreInterface&MockObject
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn($id);

        return $store;
    }
}
