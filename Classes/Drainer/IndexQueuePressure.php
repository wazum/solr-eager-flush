<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Drainer;

use TYPO3\CMS\Core\Database\ConnectionPool;
use Wazum\SolrEagerFlush\Configuration\ExtensionConfiguration;

class IndexQueuePressure
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly PendingItemPredicate $predicate,
        private readonly ExtensionConfiguration $config,
    ) {}

    public function isUnderLimit(int $rootPageId): bool
    {
        $queryBuilder = $this->connectionPool
            ->getConnectionForTable('tx_solr_indexqueue_item')
            ->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('uid')
            ->from('tx_solr_indexqueue_item')
            ->where(...$this->predicate->whereClauses($queryBuilder, time(), $this->config->typeFilter, $rootPageId))
            ->setMaxResults($this->config->indexQueueLimit + 1)
            ->executeQuery()
            ->fetchAllAssociative();

        return \count($rows) <= $this->config->indexQueueLimit;
    }
}
