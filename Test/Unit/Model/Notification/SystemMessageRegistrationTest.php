<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\Notification;

use Magento\Framework\Notification\MessageInterface;
use PHPUnit\Framework\TestCase;

/**
 * Regression test: MessageList::_loadMessages() passes each "messages" item to
 * ObjectManager::get(), so every item must be a class name (xsi:type="string"),
 * as in the core modules. An xsi:type="object" item reaches it as an instance
 * and fails with a TypeError on every admin page, hiding all system messages.
 */
class SystemMessageRegistrationTest extends TestCase
{
    public function testSystemMessagesAreRegisteredByClassName(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 4) . '/etc/adminhtml/di.xml');
        $items = $xml->xpath(
            '//type[@name="Magento\Framework\Notification\MessageList"]/arguments/argument[@name="messages"]/item'
        );

        self::assertNotEmpty($items, 'No system message registered on MessageList');
        foreach ($items as $item) {
            $type = (string) $item->attributes('xsi', true)->type;
            $class = trim((string) $item);
            self::assertSame('string', $type, sprintf('Message "%s" must use xsi:type="string"', $item['name']));
            self::assertTrue(is_subclass_of($class, MessageInterface::class), $class . ' is not a MessageInterface');
        }
    }
}
