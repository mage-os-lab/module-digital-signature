<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Model\MergeField;

use MageOS\DigitalSignature\Api\MergeFieldProviderInterface;
use MageOS\DigitalSignature\Model\MergeField\Pool;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class PoolTest extends TestCase
{
    public function testPoolValidatesInterface(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Pool(['invalid' => new \stdClass()]);
    }

    public function testPoolGetAndHas(): void
    {
        $mock = $this->createMock(MergeFieldProviderInterface::class);
        $pool = new Pool(['test' => $mock]);

        self::assertTrue($pool->has('test'));
        self::assertFalse($pool->has('non_existent'));

        self::assertSame($mock, $pool->get('test'));
        self::assertSame(['test' => $mock], $pool->getAll());
    }

    public function testPoolGetThrowsWhenNotFound(): void
    {
        $pool = new Pool([]);
        $this->expectException(LocalizedException::class);
        $pool->get('non_existent');
    }
}
