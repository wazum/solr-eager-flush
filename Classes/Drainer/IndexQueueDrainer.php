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
    private const FAILED_ITEMS_IN_REASON_MAX = 3;

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
        $pendingItemUids = $this->pendingItemUids($rootPageId);

        try {
            if ($this->siteIndexer->index($rootPageId, $deltaMax)) {
                return null;
            }

            return $this->describeReportedFailure($pendingItemUids);
        } catch (Throwable $e) {
            return $e::class . ': ' . $e->getMessage();
        }
    }

    /**
     * @return list<int>
     */
    private function pendingItemUids(int $rootPageId): array
    {
        $queryBuilder = $this->connectionPool
            ->getConnectionForTable('tx_solr_indexqueue_item')
            ->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('uid')
            ->from('tx_solr_indexqueue_item')
            ->where(...$this->predicate->whereClauses($queryBuilder, time(), $this->configuration->typeFilter, $rootPageId))
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn (array $row): int => (int) $row['uid'],
            $rows,
        );
    }

    /**
     * @param list<int> $pendingItemUids
     */
    private function describeReportedFailure(array $pendingItemUids): string
    {
        $genericReason = 'IndexService::indexItems() reported failure';
        if ([] === $pendingItemUids) {
            return $genericReason;
        }

        $queryBuilder = $this->connectionPool
            ->getConnectionForTable('tx_solr_indexqueue_item')
            ->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();

        $failedItems = $queryBuilder
            ->select('item_type', 'item_uid', 'errors')
            ->from('tx_solr_indexqueue_item')
            ->where(
                $queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($pendingItemUids, Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->neq('errors', $queryBuilder->createNamedParameter('')),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        if ([] === $failedItems) {
            return $genericReason;
        }

        $descriptions = array_map(
            static function (array $failedItem): string {
                $firstErrorLine = trim(explode("\n", (string) $failedItem['errors'], 2)[0]);

                return \sprintf('[%s:%d] "%s"', $failedItem['item_type'], $failedItem['item_uid'], $firstErrorLine);
            },
            \array_slice($failedItems, 0, self::FAILED_ITEMS_IN_REASON_MAX),
        );

        $remainderCount = \count($failedItems) - \count($descriptions);
        if ($remainderCount > 0) {
            $descriptions[] = \sprintf('and %d more', $remainderCount);
        }

        return \sprintf(
            '%s (%d failed item%s): %s',
            $genericReason,
            \count($failedItems),
            1 === \count($failedItems) ? '' : 's',
            implode('; ', $descriptions),
        );
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

        $rows = $queryBuilder->executeQuery()->fetchAllAssociative();

        return array_map(
            static fn (array $row): int => (int) $row['root'],
            $rows,
        );
    }
}
