<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Gate;

use TYPO3\CMS\Core\Database\ConnectionPool;
use Wazum\SolrEagerFlush\Configuration\ExtensionConfiguration;
use Wazum\SolrEagerFlush\Drainer\PendingItemPredicate;

final readonly class IndexQueuePressureGate implements EagerFlushGate
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private PendingItemPredicate $predicate,
        private ExtensionConfiguration $config,
    ) {}

    public function shouldProceed(): bool
    {
        $queryBuilder = $this->connectionPool
            ->getConnectionForTable('tx_solr_indexqueue_item')
            ->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();

        $count = (int) $queryBuilder
            ->count('*')
            ->from('tx_solr_indexqueue_item')
            ->where(...$this->predicate->whereClauses($queryBuilder, time()))
            ->executeQuery()
            ->fetchOne();

        return $count <= $this->config->indexQueueLimit;
    }
}
