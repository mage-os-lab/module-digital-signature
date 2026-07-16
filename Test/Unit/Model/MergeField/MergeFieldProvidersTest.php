<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\MergeField;

use MageOS\DigitalSignature\Model\MergeField\Context;
use MageOS\DigitalSignature\Model\MergeField\CustomerName;
use MageOS\DigitalSignature\Model\MergeField\GrandTotal;
use MageOS\DigitalSignature\Model\MergeField\InvoiceDate;
use MageOS\DigitalSignature\Model\MergeField\OrderDate;
use MageOS\DigitalSignature\Model\MergeField\OrderNumber;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MergeFieldProvidersTest extends TestCase
{
    private OrderInterface|MockObject $orderMock;
    private InvoiceInterface|MockObject $invoiceMock;

    protected function setUp(): void
    {
        $this->orderMock = $this->getMockBuilder(OrderInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $this->invoiceMock = $this->getMockBuilder(InvoiceInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
    }

    public function testOrderNumberProvider(): void
    {
        $this->orderMock->expects(self::once())
            ->method('getIncrementId')
            ->willReturn('100000001');

        $context = new Context($this->orderMock);
        $provider = new OrderNumber();

        self::assertSame('order_number', $provider->getCode());
        self::assertSame('100000001', $provider->resolve($context));
        self::assertTrue($provider->isAvailableForTrigger('order_placed'));
    }

    public function testGrandTotalProvider(): void
    {
        $priceCurrencyMock = $this->getMockBuilder(PriceCurrencyInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $this->orderMock->expects(self::once())
            ->method('getGrandTotal')
            ->willReturn(123.45);
        $this->orderMock->expects(self::once())
            ->method('getStoreId')
            ->willReturn(1);
        $this->orderMock->expects(self::once())
            ->method('getOrderCurrencyCode')
            ->willReturn('EUR');

        $priceCurrencyMock->expects(self::once())
            ->method('format')
            ->with(123.45, false, PriceCurrencyInterface::DEFAULT_PRECISION, 1, 'EUR')
            ->willReturn('€123.45');

        $context = new Context($this->orderMock);
        $provider = new GrandTotal($priceCurrencyMock);

        self::assertSame('grand_total', $provider->getCode());
        self::assertSame('€123.45', $provider->resolve($context));
        self::assertTrue($provider->isAvailableForTrigger('order_placed'));
    }

    public function testCustomerNameProvider(): void
    {
        $this->orderMock->expects(self::once())
            ->method('getCustomerFirstname')
            ->willReturn('Mario');
        $this->orderMock->expects(self::once())
            ->method('getCustomerLastname')
            ->willReturn('Rossi');

        $context = new Context($this->orderMock);
        $provider = new CustomerName();

        self::assertSame('customer_name', $provider->getCode());
        self::assertSame('Mario Rossi', $provider->resolve($context));
        self::assertTrue($provider->isAvailableForTrigger('order_placed'));
    }

    public function testOrderDateProvider(): void
    {
        $timezoneMock = $this->getMockBuilder(TimezoneInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $this->orderMock->expects(self::once())
            ->method('getCreatedAt')
            ->willReturn('2026-07-14 15:30:00');

        $timezoneMock->expects(self::once())
            ->method('formatDateTime')
            ->with('2026-07-14 15:30:00', \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE)
            ->willReturn('Jul 14, 2026');

        $context = new Context($this->orderMock);
        $provider = new OrderDate($timezoneMock);

        self::assertSame('order_date', $provider->getCode());
        self::assertSame('Jul 14, 2026', $provider->resolve($context));
        self::assertTrue($provider->isAvailableForTrigger('order_placed'));
    }

    public function testInvoiceDateProvider(): void
    {
        $timezoneMock = $this->getMockBuilder(TimezoneInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $this->invoiceMock->expects(self::once())
            ->method('getCreatedAt')
            ->willReturn('2026-07-14 16:00:00');

        $timezoneMock->expects(self::once())
            ->method('formatDateTime')
            ->with('2026-07-14 16:00:00', \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE)
            ->willReturn('Jul 14, 2026');

        $context = new Context($this->orderMock, $this->invoiceMock);
        $provider = new InvoiceDate($timezoneMock);

        self::assertSame('invoice_date', $provider->getCode());
        self::assertSame('Jul 14, 2026', $provider->resolve($context));
        self::assertFalse($provider->isAvailableForTrigger('order_placed'));
        self::assertTrue($provider->isAvailableForTrigger('invoice_created'));
    }

    public function testInvoiceDateProviderReturnsEmptyStringWhenNoInvoice(): void
    {
        $timezoneMock = $this->getMockBuilder(TimezoneInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $context = new Context($this->orderMock, null);
        $provider = new InvoiceDate($timezoneMock);

        self::assertSame('', $provider->resolve($context));
    }
}
