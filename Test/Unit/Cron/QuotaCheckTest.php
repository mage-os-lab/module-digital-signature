<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Cron;

use MageOS\DigitalSignature\Api\Data\QuotaStatusInterface;
use MageOS\DigitalSignature\Cron\QuotaCheck;
use MageOS\DigitalSignature\Model\Notification\Notifier;
use MageOS\DigitalSignature\Model\Provider\ProviderConfig;
use MageOS\DigitalSignature\Model\Quota\Calculator;
use Magento\AdminNotification\Model\Inbox;
use Magento\AdminNotification\Model\InboxFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class QuotaCheckTest extends TestCase
{
    private Calculator $calculator;
    private Notifier $notifier;
    private ProviderConfig $providerConfig;
    private InboxFactory $inboxFactory;
    private ScopeConfigInterface $scopeConfig;
    private LoggerInterface $logger;
    private QuotaCheck $cronJob;

    protected function setUp(): void
    {
        $this->calculator = $this->createMock(Calculator::class);
        $this->notifier = $this->createMock(Notifier::class);
        $this->providerConfig = $this->createMock(ProviderConfig::class);
        $this->inboxFactory = $this->createMock(InboxFactory::class);
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->cronJob = new QuotaCheck(
            $this->calculator,
            $this->notifier,
            $this->providerConfig,
            $this->inboxFactory,
            $this->scopeConfig,
            $this->logger
        );
    }

    public function testExecuteTriggersAlertsOnWarning(): void
    {
        $status = $this->createMock(QuotaStatusInterface::class);
        $status->method('isQuotaEnabled')->willReturn(true);
        $status->method('isWarningThreshold')->willReturn(true);
        $status->method('getProviderCode')->willReturn('wssign');
        $status->method('getFormattedMessage')->willReturn('90 / 100 used');

        $this->calculator->method('calculate')->willReturn($status);

        $inbox = $this->createMock(Inbox::class);
        $inbox->expects(self::atLeastOnce())->method('addNotice');
        $this->inboxFactory->method('create')->willReturn($inbox);

        $this->providerConfig->method('get')->willReturn('admin@example.com');
        $this->notifier->expects(self::atLeastOnce())->method('sendQuotaAlertEmail');

        $this->cronJob->execute();
    }
}
