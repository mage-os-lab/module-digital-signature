<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\TestSupport;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Models and collections for unit tests that work both against the standalone
 * stubs and inside a real Magento install (as run by check-extension), where
 * the real constructors need the full DI graph.
 *
 * Models keep all their real methods (getters/setters on the DataObject
 * storage). Collections iterate over the given items and record the
 * addFieldToFilter()/setOrder() calls, the joinLeft()/where() calls made on
 * their select and the update() calls made on their connection.
 */
trait ModelDoubles
{
    /** @var array<int, array{0: array|string, 1: string, 2: array|string}> */
    private array $joinLeftCalls = [];

    /** @var array<int, array{0: string, 1: mixed}> */
    private array $whereCalls = [];

    /** @var array<int, array{0: string, 1: mixed}> */
    private array $filterCalls = [];

    /** @var array<int, array{0: string, 1: string}> */
    private array $orderCalls = [];

    /** @var array<int, array{0: string, 1: array, 2: mixed}> */
    private array $updateCalls = [];

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T&MockObject
     */
    private function newModel(string $class): object
    {
        return $this->getMockBuilder($class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param object[] $items
     * @return T&MockObject
     */
    private function newCollection(string $class, array $items = []): object
    {
        $select = $this->getMockBuilder(Select::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['joinLeft', 'where'])
            ->getMock();
        $select->method('joinLeft')->willReturnCallback(
            function ($name, $cond, $cols = '*') use ($select) {
                $this->joinLeftCalls[] = [$name, $cond, $cols];

                return $select;
            }
        );
        $select->method('where')->willReturnCallback(
            function ($cond, $value = null) use ($select) {
                $this->whereCalls[] = [$cond, $value];

                return $select;
            }
        );

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('quoteInto')->willReturnCallback(
            static fn($text, $value): string => str_replace('?', "'" . $value . "'", (string)$text)
        );
        $connection->method('update')->willReturnCallback(
            function ($table, array $bind, $where = '') {
                $this->updateCalls[] = [$table, $bind, $where];

                return 1;
            }
        );

        $collection = $this->getMockBuilder($class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'addFieldToFilter',
                'setPageSize',
                'setOrder',
                'getSelect',
                'getConnection',
                'getTable',
                'getIterator',
            ])
            ->getMock();
        $collection->method('addFieldToFilter')->willReturnCallback(
            function ($field, $condition = null) use (&$collection) {
                $this->filterCalls[] = [$field, $condition];

                return $collection;
            }
        );
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('setOrder')->willReturnCallback(
            function ($field, $direction = 'DESC') use (&$collection) {
                $this->orderCalls[] = [$field, $direction];

                return $collection;
            }
        );
        $collection->method('getSelect')->willReturn($select);
        $collection->method('getConnection')->willReturn($connection);
        $collection->method('getTable')->willReturnArgument(0);
        $collection->method('getIterator')->willReturnCallback(
            static fn(): \ArrayIterator => new \ArrayIterator($items)
        );

        return $collection;
    }
}
