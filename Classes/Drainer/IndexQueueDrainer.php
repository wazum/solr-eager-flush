<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Drainer;

use Throwable;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use Wazum\SolrEagerFlush\Configuration\ExtensionConfiguration;
use Wazum\SolrEagerFlush\Site\SiteEagerFlushPolicy;
use Wazum\SolrEagerFlush\Site\SolrReachability;

class IndexQueueDrainer
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly PendingItemPredicate $predicate,
        private readonly SiteIndexer $siteIndexer,
        private readonly SiteEagerFlushPolicy $siteEagerFlushPolicy,
        private readonly SolrReachability $solrReachability,
        private readonly ExtensionConfiguration $configuration,
    ) {}

    public function drain(int $deltaMax, ?int $onlyRootPageId = null): DrainResult
    {
        $rootPageIds = $this->affectedRootPageIds($onlyRootPageId);
        if ([] === $rootPageIds) {
            return new DrainResult();
        }

        $succeeded = [];
        $failed = [];
        $failureReasons = [];

        foreach ($rootPageIds as $rootPageId) {
            if (!$this->isFlushableSite($rootPageId)) {
                continue;
            }

            $failureReason = $this->indexRoot($rootPageId, $deltaMax);
            if (null === $failureReason) {
                $succeeded[] = $rootPageId;
                continue;
            }

            $failed[] = $rootPageId;
            $failureReasons[$rootPageId] = $failureReason;
        }

        return new DrainResult($succeeded, $failed, $failureReasons);
    }

    private function isFlushableSite(int $rootPageId): bool
    {
        return $this->siteEagerFlushPolicy->isEnabledForRoot($rootPageId)
            && $this->solrReachability->isReachable($rootPageId);
    }

    private function indexRoot(int $rootPageId, int $deltaMax): ?string
    {
        try {
            if ($this->siteIndexer->index($rootPageId, $deltaMax)) {
                return null;
            }

            return 'IndexService::indexItems() reported failure';
        } catch (Throwable $e) {
            return $e::class . ': ' . $e->getMessage();
        }
    }

    /**
     * @return list<int>
     */
    private function affectedRootPageIds(?int $onlyRootPageId): array
    {
        $queryBuilder = $this->connectionPool
            ->getConnectionForTable('tx_solr_indexqueue_item')
            ->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();

        $queryBuilder
            ->select('root')
            ->distinct()
            ->from('tx_solr_indexqueue_item')
            ->where(...$this->predicate->whereClauses($queryBuilder, time(), $this->configuration->typeFilter));

        if (null !== $onlyRootPageId) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('root', $queryBuilder->createNamedParameter($onlyRootPageId, Connection::PARAM_INT)),
            );
        }

        try {
            $rows = $queryBuilder->executeQuery()->fetchAllAssociative();
        } catch (Throwable) {
            return [];
        }

        return array_map(
            static fn (array $row): int => (int) $row['root'],
            $rows,
        );
    }
}
