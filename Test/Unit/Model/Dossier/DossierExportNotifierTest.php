<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Dossier;

use MageOS\DigitalSignature\Model\Dossier\DossierExportNotifier;
use Magento\AdminNotification\Model\Inbox;
use Magento\AdminNotification\Model\InboxFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DossierExportNotifierTest extends TestCase
{
    private Inbox&MockObject $inbox;
    private LoggerInterface&MockObject $logger;
    private DossierExportNotifier $notifier;

    protected function setUp(): void
    {
        $this->inbox = $this->createMock(Inbox::class);
        $factory = $this->createStub(InboxFactory::class);
        $factory->method('create')->willReturn($this->inbox);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->notifier = new DossierExportNotifier($factory, $this->logger);
    }

    public function testReadyAddsANoticePointingToTheGrid(): void
    {
        $this->inbox->expects(self::once())->method('addNotice')->with(
            self::callback(fn ($title) => str_contains((string)$title, '#12')),
            self::callback(fn ($text) => str_contains((string)$text, 'Dossier exports')
                && str_contains((string)$text, '72'))
        );

        $this->notifier->ready(12, 300, 72);
    }

    public function testFailedAddsAMajorMessageWithTheReason(): void
    {
        $this->inbox->expects(self::once())->method('addMajor')->with(
            self::callback(fn ($title) => str_contains((string)$title, '#12')),
            self::callback(fn ($text) => str_contains((string)$text, 'disk full'))
        );

        $this->notifier->failed(12, 'disk full');
    }

    public function testNotificationFailureIsLoggedNotThrown(): void
    {
        $this->inbox->method('addNotice')->willThrowException(new \RuntimeException('db down'));
        $this->logger->expects(self::once())->method('warning');

        $this->notifier->ready(12, 300, 72);
    }
}
