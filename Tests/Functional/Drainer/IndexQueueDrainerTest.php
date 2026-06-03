<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Tests\Functional\Drainer;

use ApacheSolrForTypo3\Solr\Domain\Site\SiteRepository;
use Throwable;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Wazum\SolrEagerFlush\Drainer\IndexQueueDrainer;
use Wazum\SolrEagerFlush\PendingPredicate\PendingItemPredicate;
use Wazum\SolrEagerFlush\RunContext\EagerFlushRunContext;
use Wazum\SolrEagerFlush\Tests\Functional\AbstractFunctionalTestCase;

final class IndexQueueDrainerTest extends AbstractFunctionalTestCase
{
    public function testDrainExitsCleanlyWhenIndexQueueIsEmpty(): void
    {
        $runContext = new EagerFlushRunContext();
        $drainer = $this->buildDrainer($runContext);

        $drainer->drain(10);

        self::assertFalse($runContext->isActive());
    }

    public function testDrainResolvesAffectedSitesViaDistinctRootQuery(): void
    {
        $now = time();
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_solr_indexqueue_item');
        $connection->insert('tx_solr_indexqueue_item', [
            'root' => 42,
            'item_type' => 'pages',
            'item_uid' => 1,
            'indexing_configuration' => 'pages',
            'changed' => $now,
            'indexed' => 0,
            'errors' => '',
            'indexing_priority' => 0,
        ]);

        $rootPids = $this->affectedRoots();

        self::assertSame([42], $rootPids);
    }

    public function testRunContextIsReleasedEvenWhenIndexingThrows(): void
    {
        $now = time();
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_solr_indexqueue_item')
            ->insert('tx_solr_indexqueue_item', [
                'root' => 99,
                'item_type' => 'pages',
                'item_uid' => 1,
                'indexing_configuration' => 'pages',
                'changed' => $now,
                'indexed' => 0,
                'errors' => '',
                'indexing_priority' => 0,
            ]);

        $runContext = new EagerFlushRunContext();
        $drainer = $this->buildDrainer($runContext);

        try {
            $drainer->drain(10);
        } catch (Throwable) {
        }

        self::assertFalse(
            $runContext->isActive(),
            'RunContext must be released even if IndexService throws (try/finally pattern)',
        );
    }

    private function buildDrainer(EagerFlushRunContext $runContext): IndexQueueDrainer
    {
        return new IndexQueueDrainer(
            GeneralUtility::makeInstance(ConnectionPool::class),
            new PendingItemPredicate(),
            GeneralUtility::makeInstance(SiteRepository::class),
            $runContext,
        );
    }

    /**
     * @return list<int>
     */
    private function affectedRoots(): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_solr_indexqueue_item')
            ->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('root')
            ->distinct()
            ->from('tx_solr_indexqueue_item')
            ->where(...(new PendingItemPredicate())->whereClauses($queryBuilder, time()))
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn (array $row): int => (int) $row['root'], $rows);
    }
}
