<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Tests\Functional\Drainer;

use RuntimeException;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Wazum\SolrEagerFlush\Drainer\IndexQueueDrainer;
use Wazum\SolrEagerFlush\Indexing\SiteIndexer;
use Wazum\SolrEagerFlush\PendingPredicate\PendingItemPredicate;
use Wazum\SolrEagerFlush\RunContext\EagerFlushRunContext;
use Wazum\SolrEagerFlush\Tests\Functional\AbstractFunctionalTestCase;

final class IndexQueueDrainerTest extends AbstractFunctionalTestCase
{
    public function testDrainExitsCleanlyWhenIndexQueueIsEmpty(): void
    {
        $indexer = $this->recordingIndexer();
        $runContext = new EagerFlushRunContext();

        $result = $this->buildDrainer($runContext, $indexer)->drain(10);

        self::assertSame([], $indexer->attempted, 'No site indexed when queue is empty');
        self::assertSame([], $result->succeededRoots);
        self::assertSame([], $result->failedRoots);
        self::assertFalse($runContext->isActive());
    }

    public function testIndexesEachAffectedRootAndReportsSuccess(): void
    {
        $this->insertPendingItem(root: 1);
        $this->insertPendingItem(root: 2);
        $indexer = $this->recordingIndexer();

        $result = $this->buildDrainer(new EagerFlushRunContext(), $indexer)->drain(10);

        self::assertEqualsCanonicalizing([1, 2], $indexer->attempted);
        self::assertEqualsCanonicalizing([1, 2], $result->succeededRoots);
        self::assertSame([], $result->failedRoots);
    }

    public function testContinuesToRemainingRootsWhenOneFailsAndReportsOutcome(): void
    {
        $this->insertPendingItem(root: 1);
        $this->insertPendingItem(root: 2);
        $indexer = new class implements SiteIndexer {
            /** @var list<int> */
            public array $attempted = [];

            public function index(int $rootPageId, int $limit): bool
            {
                $this->attempted[] = $rootPageId;
                if (1 === $rootPageId) {
                    throw new RuntimeException('boom');
                }

                return true;
            }
        };
        $runContext = new EagerFlushRunContext();

        $result = $this->buildDrainer($runContext, $indexer)->drain(10);

        self::assertEqualsCanonicalizing([1, 2], $indexer->attempted, 'A failing root must not abort the others');
        self::assertSame([2], $result->succeededRoots);
        self::assertSame([1], $result->failedRoots);
        self::assertFalse($runContext->isActive(), 'RunContext released despite a failing root');
    }

    public function testTreatsUnsuccessfulIndexingAsFailure(): void
    {
        $this->insertPendingItem(root: 1);
        $indexer = new class implements SiteIndexer {
            /** @var list<int> */
            public array $attempted = [];

            public function index(int $rootPageId, int $limit): bool
            {
                $this->attempted[] = $rootPageId;

                return false;
            }
        };

        $result = $this->buildDrainer(new EagerFlushRunContext(), $indexer)->drain(10);

        self::assertSame([], $result->succeededRoots);
        self::assertSame([1], $result->failedRoots);
    }

    private function buildDrainer(EagerFlushRunContext $runContext, SiteIndexer $indexer): IndexQueueDrainer
    {
        return new IndexQueueDrainer(
            GeneralUtility::makeInstance(ConnectionPool::class),
            new PendingItemPredicate(),
            $runContext,
            $indexer,
        );
    }

    private function recordingIndexer(): RecordingSiteIndexer
    {
        return new RecordingSiteIndexer();
    }

    private function insertPendingItem(int $root): void
    {
        $now = time();
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_solr_indexqueue_item')
            ->insert('tx_solr_indexqueue_item', [
                'root' => $root,
                'item_type' => 'pages',
                'item_uid' => $root,
                'indexing_configuration' => 'pages',
                'changed' => $now,
                'indexed' => 0,
                'errors' => '',
                'indexing_priority' => 0,
            ]);
    }
}
