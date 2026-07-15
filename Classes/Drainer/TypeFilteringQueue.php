<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Drainer;

use ApacheSolrForTypo3\Solr\Domain\Site\Site;
use ApacheSolrForTypo3\Solr\IndexQueue\Item;
use ApacheSolrForTypo3\Solr\IndexQueue\Queue;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Wazum\SolrEagerFlush\TypeFilter\TypeFilterMode;

final class TypeFilteringQueue extends Queue
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly PendingItemPredicate $predicate,
        private readonly TypeFilterMode $typeFilter,
    ) {
        parent::__construct();
    }

    /**
     * @return list<Item>
     */
    public function getItemsToIndex(Site $site, int $limit = 50): array
    {
        return array_map(
            static fn (array $record): Item => GeneralUtility::makeInstance(Item::class, $record),
            $this->eligibleItemRecords($site->getRootPageId(), $limit),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function eligibleItemRecords(int $rootPageId, int $limit): array
    {
        $queryBuilder = $this->connectionPool
            ->getConnectionForTable('tx_solr_indexqueue_item')
            ->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('*')
            ->from('tx_solr_indexqueue_item')
            ->where(...$this->predicate->whereClauses($queryBuilder, time(), $this->typeFilter, $rootPageId))
            ->orderBy('indexing_priority', 'DESC')
            ->addOrderBy('changed', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
