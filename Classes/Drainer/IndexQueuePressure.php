<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Drainer;

use Throwable;
use TYPO3\CMS\Core\Database\ConnectionPool;
use Wazum\SolrEagerFlush\Configuration\ExtensionConfiguration;

class IndexQueuePressure
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly PendingItemPredicate $predicate,
        private readonly ExtensionConfiguration $configuration,
    ) {}

    public function isUnderLimit(int $rootPageId): bool
    {
        $queryBuilder = $this->connectionPool
            ->getConnectionForTable('tx_solr_indexqueue_item')
            ->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();

        try {
            $rows = $queryBuilder
                ->select('uid')
                ->from('tx_solr_indexqueue_item')
                ->where(...$this->predicate->whereClauses($queryBuilder, time(), $this->configuration->typeFilter, $rootPageId))
                ->setMaxResults($this->configuration->indexQueueLimit + 1)
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (Throwable) {
            return false;
        }

        return \count($rows) <= $this->configuration->indexQueueLimit;
    }
}
