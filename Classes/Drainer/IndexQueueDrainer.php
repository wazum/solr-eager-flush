<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Drainer;

use ApacheSolrForTypo3\Solr\Domain\Index\IndexService;
use ApacheSolrForTypo3\Solr\Domain\Site\SiteRepository;
use Throwable;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Wazum\SolrEagerFlush\PendingPredicate\PendingItemPredicate;
use Wazum\SolrEagerFlush\RunContext\EagerFlushRunContext;

class IndexQueueDrainer
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly PendingItemPredicate $predicate,
        private readonly SiteRepository $siteRepository,
        private readonly EagerFlushRunContext $runContext,
    ) {}

    public function drain(int $deltaMax): void
    {
        $rootPids = $this->affectedRootPids();
        if ([] === $rootPids) {
            return;
        }

        $this->runContext->enter();
        try {
            foreach ($rootPids as $rootPid) {
                try {
                    $site = $this->siteRepository->getSiteByRootPageId($rootPid);
                } catch (Throwable) {
                    continue;
                }
                GeneralUtility::makeInstance(IndexService::class, $site)
                    ->indexItems($deltaMax);
            }
        } finally {
            $this->runContext->leave();
        }
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
