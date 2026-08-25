<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Test\Unit\Plugin\Webapi;

use MageOS\DigitalSignature\Api\DocumentRepositoryInterface;
use MageOS\DigitalSignature\Model\Service\ApiConsumerScope;
use MageOS\DigitalSignature\Plugin\Webapi\DocumentScopePlugin;
use MageOS\DigitalSignature\TestSupport\FakeDocument;
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
            new FilterBuilder(),
            new FilterGroupBuilder()
        );
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

            public function setFilterGroups(array $filterGroups)
            {
                $this->groups = $filterGroups;

                return $this;
            }

            public function setCurrentPage($currentPage)
            {
                return $this;
            }

            public function setPageSize($pageSize)
            {
                return $this;
            }
        };
    }
}
