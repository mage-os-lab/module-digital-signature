<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\Plugin\Webapi;

use MageOS\DigitalSignature\Api\Data\DocumentInterface;
use MageOS\DigitalSignature\Api\DocumentRepositoryInterface;
use MageOS\DigitalSignature\Model\Service\ApiConsumerScope;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Applies the calling integration's store scope (ApiConsumerScope) to
 * every document read via API: a document outside the scope results in a
 * 404 (not 403), so as not to reveal its existence — same principle
 * already used for the customer download.
 */
class DocumentScopePlugin
{
    /** impossible store_id: used to force zero results (fail-closed) */
    private const IMPOSSIBLE_STORE_ID = '-1';

    public function __construct(
        private readonly ApiConsumerScope $apiConsumerScope,
        private readonly FilterBuilder $filterBuilder,
        private readonly FilterGroupBuilder $filterGroupBuilder
    ) {
    }

    public function afterGetById(DocumentRepositoryInterface $subject, DocumentInterface $result): DocumentInterface
    {
        if (!$this->apiConsumerScope->isStoreAllowed((int)($result->getStoreId() ?? 0))) {
            throw new NoSuchEntityException(
                __('Document with id "%1" does not exist.', (string)$result->getDocumentId())
            );
        }

        return $result;
    }

    /**
     * Injects the scope filter BEFORE query execution: filtering the
     * already-extracted page (after-plugin) would break pagination and total_count.
     *
     * @param DocumentRepositoryInterface $subject
     * @param SearchCriteriaInterface $searchCriteria
     * @return array{0: SearchCriteriaInterface}
     */
    public function beforeGetList(
        DocumentRepositoryInterface $subject,
        SearchCriteriaInterface $searchCriteria
    ): array {
        $allowed = $this->apiConsumerScope->getAllowedStoreIds();
        if ($allowed === null) {
            return [$searchCriteria];
        }

        // No store allowed: impossible filter instead of no filter,
        // so the query returns zero rows (fail-closed).
        $value = $allowed === []
            ? self::IMPOSSIBLE_STORE_ID
            : implode(',', array_map('intval', $allowed));

        $filter = $this->filterBuilder
            ->setField(DocumentInterface::STORE_ID)
            ->setConditionType('in')
            ->setValue($value)
            ->create();
        $filterGroup = $this->filterGroupBuilder->addFilter($filter)->create();

        $groups = $searchCriteria->getFilterGroups();
        $groups[] = $filterGroup;
        $searchCriteria->setFilterGroups($groups);

        return [$searchCriteria];
    }
}
