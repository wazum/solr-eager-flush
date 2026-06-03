<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Drainer;

use Throwable;
use TYPO3\CMS\Core\Database\ConnectionPool;
use Wazum\SolrEagerFlush\Indexing\SiteIndexer;
use Wazum\SolrEagerFlush\PendingPredicate\PendingItemPredicate;
use Wazum\SolrEagerFlush\RunContext\EagerFlushRunContext;
use Wazum\SolrEagerFlush\Site\SiteEagerFlushPolicy;

class IndexQueueDrainer
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly PendingItemPredicate $predicate,
        private readonly EagerFlushRunContext $runContext,
        private readonly SiteIndexer $siteIndexer,
        private readonly SiteEagerFlushPolicy $siteEagerFlushPolicy,
    ) {}

    public function drain(int $deltaMax, ?int $onlyRootPageId = null): DrainResult
    {
        $rootPids = $this->affectedRootPids();
        if (null !== $onlyRootPageId) {
            $rootPids = array_values(array_intersect($rootPids, [$onlyRootPageId]));
        }
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
    private function affectedRootPids(): array
    {
        $queryBuilder = $this->connectionPool
            ->getConnectionForTable('tx_solr_indexqueue_item')
            ->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('root')
            ->distinct()
            ->from('tx_solr_indexqueue_item')
            ->where(...$this->predicate->whereClauses($queryBuilder, time()))
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn (array $row): int => (int) $row['root'],
            $rows,
        );
    }
}
