<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Quota;

use MageOS\DigitalSignature\Model\Provider\ProviderConfig;
use MageOS\DigitalSignature\Model\Quota\Calculator;
use MageOS\DigitalSignature\Model\ResourceModel\Document\Collection;
use MageOS\DigitalSignature\Model\ResourceModel\Document\CollectionFactory;
use PHPUnit\Framework\TestCase;

class CalculatorTest extends TestCase
{
    private ProviderConfig $providerConfig;
    private CollectionFactory $collectionFactory;
    private Calculator $calculator;

    protected function setUp(): void
    {
        $this->providerConfig = $this->createMock(ProviderConfig::class);
        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $this->calculator = new Calculator($this->providerConfig, $this->collectionFactory);
    }

    public function testDisabledQuotaReturnsDisabledStatus(): void
    {
        $this->providerConfig->method('get')->willReturnMap([
            ['wssign', 'quota_enabled', null, '0'],
            ['wssign', 'total_quota', null, '500']
        ]);

        $status = $this->calculator->calculate('wssign');

        self::assertFalse($status->isQuotaEnabled());
        self::assertSame('wssign', $status->getProviderCode());
    }

    public function testPrepaidPoolCalculationWithWarningAndExhausted(): void
    {
        $this->providerConfig->method('get')->willReturnMap([
            ['wssign', 'quota_enabled', null, '1'],
            ['wssign', 'total_quota', null, '100'],
            ['wssign', 'quota_type', null, 'prepaid_pool'],
            ['wssign', 'alert_threshold_percent', null, '15'],
            ['wssign', 'alert_threshold_count', null, '20'],
            ['wssign', 'quota_start_date', null, '2026-01-01']
        ]);

        $collection = $this->createMock(Collection::class);
        $collection->expects(self::once())->method('getSize')->willReturn(92);
        $this->collectionFactory->method('create')->willReturn($collection);

        $status = $this->calculator->calculate('wssign');

        self::assertTrue($status->isQuotaEnabled());
        self::assertSame(100, $status->getTotalQuota());
        self::assertSame(92, $status->getUsedQuota());
        self::assertSame(8, $status->getRemainingQuota());
        self::assertSame(92.0, $status->getPercentageUsed());
        self::assertTrue($status->isWarningThreshold());
        self::assertFalse($status->isExhausted());
    }
}
