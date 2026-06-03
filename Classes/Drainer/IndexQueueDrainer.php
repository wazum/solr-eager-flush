<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Drainer;

use Throwable;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use Wazum\SolrEagerFlush\Site\SiteEagerFlushPolicy;
use Wazum\SolrEagerFlush\Site\SolrReachability;

class IndexQueueDrainer
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly PendingItemPredicate $predicate,
        private readonly EagerFlushRunContext $runContext,
        private readonly SiteIndexer $siteIndexer,
        private readonly SiteEagerFlushPolicy $siteEagerFlushPolicy,
        private readonly SolrReachability $solrReachability,
    ) {}

    public function drain(int $deltaMax, ?int $onlyRootPageId = null): DrainResult
    {
        $rootPids = $this->affectedRootPids($onlyRootPageId);
        if ([] === $rootPids) {
            return new DrainResult();
        }

        $succeeded = [];
        $failed = [];

        $this->runContext->enter();
        try {
            foreach ($rootPids as $rootPid) {
                if (!$this->siteEagerFlushPolicy->isEnabledForRoot($rootPid)) {
                    continue;
                }
                if (!$this->solrReachability->isReachable($rootPid)) {
                    continue;
                }
                try {
                    $this->siteIndexer->index($rootPid, $deltaMax)
                        ? $succeeded[] = $rootPid
                        : $failed[] = $rootPid;
                } catch (Throwable) {
                    $failed[] = $rootPid;
                }
            }
        } finally {
            $this->runContext->leave();
        }

        return new DrainResult($succeeded, $failed);
    }

    /**
     * @return list<int>
     */
    private function affectedRootPids(?int $onlyRootPageId): array
    {
        $queryBuilder = $this->connectionPool
            ->getConnectionForTable('tx_solr_indexqueue_item')
            ->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();

        $queryBuilder
            ->select('root')
            ->distinct()
            ->from('tx_solr_indexqueue_item')
            ->where(...$this->predicate->whereClauses($queryBuilder, time()));

        if (null !== $onlyRootPageId) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('root', $queryBuilder->createNamedParameter($onlyRootPageId, Connection::PARAM_INT)),
            );
        }

        $rows = $queryBuilder->executeQuery()->fetchAllAssociative();

        return array_map(
            static fn (array $row): int => (int) $row['root'],
            $rows,
        );
    }
}
