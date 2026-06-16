<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Drainer;

use ApacheSolrForTypo3\Solr\Domain\Site\Site;
use ApacheSolrForTypo3\Solr\IndexQueue\Item;
use ApacheSolrForTypo3\Solr\IndexQueue\Queue;
use TYPO3\CMS\Core\Database\ConnectionPool;
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
        $items = array_map(
            fn (int $uid): ?Item => $this->getItem($uid),
            $this->eligibleItemUids($site->getRootPageId(), $limit),
        );

        return array_values(array_filter($items));
    }

    /**
     * @return list<int>
     */
    private function eligibleItemUids(int $rootPageId, int $limit): array
    {
        $queryBuilder = $this->connectionPool
            ->getConnectionForTable('tx_solr_indexqueue_item')
            ->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('uid')
            ->from('tx_solr_indexqueue_item')
            ->where(...$this->predicate->whereClauses($queryBuilder, time(), $this->typeFilter, $rootPageId))
            ->orderBy('indexing_priority', 'DESC')
            ->addOrderBy('changed', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn (array $row): int => (int) $row['uid'], $rows);
    }
}
