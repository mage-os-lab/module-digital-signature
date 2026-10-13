<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Plugin\Webapi;

use MageOS\DigitalSignature\Api\DocumentRepositoryInterface;
use MageOS\DigitalSignature\Model\Service\ApiConsumerScope;
use MageOS\DigitalSignature\Plugin\Webapi\DocumentScopePlugin;
use MageOS\DigitalSignature\TestSupport\FakeDocument;
use Magento\Framework\Api\Filter;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroup;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DocumentScopePluginTest extends TestCase
{
    private ApiConsumerScope&MockObject $scope;
    private DocumentRepositoryInterface&MockObject $subject;
    private DocumentScopePlugin $plugin;

    protected function setUp(): void
    {
        $this->scope = $this->createMock(ApiConsumerScope::class);
        $this->subject = $this->createMock(DocumentRepositoryInterface::class);
        $this->plugin = new DocumentScopePlugin(
            $this->scope,
            $this->createFilterBuilder(),
            $this->createFilterGroupBuilder()
        );
    }

    /**
     * Builder doubles that assemble real Filter/FilterGroup objects: the real
     * builders need an ObjectFactory, which is not available standalone.
     */
    private function createFilterBuilder(): FilterBuilder
    {
        $state = new \ArrayObject(['field' => '', 'condition_type' => 'eq', 'value' => null]);
        $builder = $this->createMock(FilterBuilder::class);
        foreach (['setField' => 'field', 'setConditionType' => 'condition_type', 'setValue' => 'value'] as $method => $key) {
            $builder->method($method)->willReturnCallback(
                static function ($value) use ($state, $key, $builder) {
                    $state[$key] = $value;

                    return $builder;
                }
            );
        }
        $builder->method('create')->willReturnCallback(
            static fn () => (new Filter())
                ->setField($state['field'])
                ->setConditionType($state['condition_type'])
                ->setValue($state['value'])
        );

        return $builder;
    }

    private function createFilterGroupBuilder(): FilterGroupBuilder
    {
        $filters = new \ArrayObject();
        $builder = $this->createMock(FilterGroupBuilder::class);
        $builder->method('addFilter')->willReturnCallback(
            static function (Filter $filter) use ($filters, $builder) {
                $filters[] = $filter;

                return $builder;
            }
        );
        $builder->method('create')->willReturnCallback(
            static function () use ($filters) {
                $group = (new FilterGroup())->setFilters($filters->getArrayCopy());
                $filters->exchangeArray([]);

                return $group;
            }
        );

        return $builder;
    }

    public function testAfterGetByIdAllowsDocumentInScope(): void
    {
        $document = new FakeDocument();
        $document->setStoreId(1);
        $this->scope->method('isStoreAllowed')->with(1)->willReturn(true);

        self::assertSame($document, $this->plugin->afterGetById($this->subject, $document));
    }

    public function testAfterGetByIdThrowsForDocumentOutOfScope(): void
    {
        $document = new FakeDocument();
        $document->documentId = 3;
        $document->setStoreId(2);
        $this->scope->method('isStoreAllowed')->with(2)->willReturn(false);

        $this->expectException(NoSuchEntityException::class);

        $this->plugin->afterGetById($this->subject, $document);
    }

    public function testBeforeGetListAddsNoFilterWhenUnrestricted(): void
    {
        $searchCriteria = $this->createSearchCriteria();
        $this->scope->method('getAllowedStoreIds')->willReturn(null);

        $result = $this->plugin->beforeGetList($this->subject, $searchCriteria);

        self::assertSame([$searchCriteria], $result);
        self::assertSame([], $searchCriteria->getFilterGroups());
    }

    public function testBeforeGetListInjectsStoreFilterWhenScoped(): void
    {
        $searchCriteria = $this->createSearchCriteria();
        $this->scope->method('getAllowedStoreIds')->willReturn([1, 3]);

        $this->plugin->beforeGetList($this->subject, $searchCriteria);

        $groups = $searchCriteria->getFilterGroups();
        self::assertCount(1, $groups);
        $filters = $groups[0]->getFilters();
        self::assertCount(1, $filters);
        self::assertSame('store_id', $filters[0]->getField());
        self::assertSame('in', $filters[0]->getConditionType());
        self::assertSame('1,3', $filters[0]->getValue());
    }

    public function testBeforeGetListPreservesExistingFilterGroups(): void
    {
        $existing = new FilterGroup();
        $searchCriteria = $this->createSearchCriteria([$existing]);
        $this->scope->method('getAllowedStoreIds')->willReturn([2]);

        $this->plugin->beforeGetList($this->subject, $searchCriteria);

        $groups = $searchCriteria->getFilterGroups();
        self::assertCount(2, $groups);
        self::assertSame($existing, $groups[0]);
        self::assertSame('2', $groups[1]->getFilters()[0]->getValue());
    }

    public function testBeforeGetListInjectsImpossibleFilterWhenNoStoreAllowed(): void
    {
        $searchCriteria = $this->createSearchCriteria();
        $this->scope->method('getAllowedStoreIds')->willReturn([]);

        $this->plugin->beforeGetList($this->subject, $searchCriteria);

        $filters = $searchCriteria->getFilterGroups()[0]->getFilters();
        self::assertSame('store_id', $filters[0]->getField());
        self::assertSame('in', $filters[0]->getConditionType());
        self::assertSame('-1', $filters[0]->getValue());
    }

    /**
     * @param FilterGroup[] $groups
     */
    private function createSearchCriteria(array $groups = []): SearchCriteriaInterface
    {
        return new class ($groups) implements SearchCriteriaInterface {
            /** @param FilterGroup[] $groups */
            public function __construct(private array $groups)
            {
            }

            public function getFilterGroups()
            {
                return $this->groups;
            }

            public function setFilterGroups(?array $filterGroups = null)
            {
                $this->groups = $filterGroups ?? [];

                return $this;
            }

            public function getSortOrders()
            {
                return null;
            }

            public function setSortOrders(?array $sortOrders = null)
            {
                return $this;
            }

            public function getPageSize()
            {
                return null;
            }

            public function setPageSize($pageSize)
            {
                return $this;
            }

            public function getCurrentPage()
            {
                return null;
            }

            public function setCurrentPage($currentPage)
            {
                return $this;
            }
        };
    }
}
