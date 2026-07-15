<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Tests\Functional\Drainer;

use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Wazum\SolrEagerFlush\Configuration\ExtensionConfiguration;
use Wazum\SolrEagerFlush\Drainer\IndexQueueDrainer;
use Wazum\SolrEagerFlush\Drainer\PendingItemPredicate;
use Wazum\SolrEagerFlush\Drainer\SiteIndexer;
use Wazum\SolrEagerFlush\Site\SiteEagerFlushPolicy;
use Wazum\SolrEagerFlush\Site\SolrReachability;
use Wazum\SolrEagerFlush\Tests\Functional\AbstractFunctionalTestCase;
use Wazum\SolrEagerFlush\TypeFilter\TypeFilterMode;

final class IndexQueueDrainerTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function drainExitsCleanlyWhenIndexQueueIsEmpty(): void
    {
        $indexer = $this->recordingIndexer();

        $result = $this->buildDrainer($indexer)->drain(10);

        self::assertSame([], $indexer->attempted, 'No site indexed when queue is empty');
        self::assertSame([], $result->succeededRoots);
        self::assertSame([], $result->failedRoots);
    }

    #[Test]
    public function indexesEachAffectedRootAndReportsSuccess(): void
    {
        $this->insertPendingItem(root: 1);
        $this->insertPendingItem(root: 2);
        $indexer = $this->recordingIndexer();

        $result = $this->buildDrainer($indexer)->drain(10);

        self::assertEqualsCanonicalizing([1, 2], $indexer->attempted);
        self::assertEqualsCanonicalizing([1, 2], $result->succeededRoots);
        self::assertSame([], $result->failedRoots);
    }

    #[Test]
    public function continuesToRemainingRootsWhenOneFailsAndReportsOutcome(): void
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

        $result = $this->buildDrainer($indexer)->drain(10);

        self::assertEqualsCanonicalizing([1, 2], $indexer->attempted, 'A failing root must not abort the others');
        self::assertSame([2], $result->succeededRoots);
        self::assertSame([1], $result->failedRoots);
    }

    #[Test]
    public function treatsUnsuccessfulIndexingAsFailure(): void
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

        $result = $this->buildDrainer($indexer)->drain(10);

        self::assertSame([], $result->succeededRoots);
        self::assertSame([1], $result->failedRoots);
    }

    #[Test]
    public function recordsFailureReasonForThrowingRoot(): void
    {
        $this->insertPendingItem(root: 1);
        $indexer = new class implements SiteIndexer {
            public function index(int $rootPageId, int $limit): bool
            {
                throw new RuntimeException('solr exploded');
            }
        };

        $result = $this->buildDrainer($indexer)->drain(10);

        self::assertSame([1], $result->failedRoots);
        self::assertArrayHasKey(1, $result->failureReasons);
        self::assertStringContainsString('solr exploded', $result->failureReasons[1]);
    }

    #[Test]
    public function flushesOnlyTheScopedRootWhenGiven(): void
    {
        $this->insertPendingItem(root: 1);
        $this->insertPendingItem(root: 2);
        $indexer = $this->recordingIndexer();

        $result = $this->buildDrainer($indexer)->drain(10, onlyRootPageId: 1);

        self::assertSame([1], $indexer->attempted, 'Only the scoped root is indexed');
        self::assertSame([1], $result->succeededRoots);
    }

    #[Test]
    public function fallsBackToAllRootsWhenScopeIsNull(): void
    {
        $this->insertPendingItem(root: 1);
        $this->insertPendingItem(root: 2);
        $indexer = $this->recordingIndexer();

        $this->buildDrainer($indexer)->drain(10, onlyRootPageId: null);

        self::assertEqualsCanonicalizing([1, 2], $indexer->attempted, 'Unresolved scope flushes all pending roots');
    }

    #[Test]
    public function scopedRootWithNoPendingItemsIndexesNothing(): void
    {
        $this->insertPendingItem(root: 1);
        $indexer = $this->recordingIndexer();

        $this->buildDrainer($indexer)->drain(10, onlyRootPageId: 999);

        self::assertSame([], $indexer->attempted, 'A resolved-but-not-pending root does not fall back to all roots');
    }

    #[Test]
    public function excludesRootsWhoseOnlyPendingItemsAreOfAFilteredType(): void
    {
        $this->insertPendingItem(root: 1, itemType: 'pages');
        $indexer = $this->recordingIndexer();

        $this->buildDrainer($indexer, typeFilter: TypeFilterMode::Records)->drain(10);

        self::assertSame([], $indexer->attempted, 'A root with only excluded-type items is not treated as affected');
    }

    #[Test]
    public function includesRootsWithPendingItemsMatchingTheTypeFilter(): void
    {
        $this->insertPendingItem(root: 1, itemType: 'tx_news_domain_model_news');
        $indexer = $this->recordingIndexer();

        $result = $this->buildDrainer($indexer, typeFilter: TypeFilterMode::Records)->drain(10);

        self::assertSame([1], $indexer->attempted);
        self::assertSame([1], $result->succeededRoots);
    }

    #[Test]
    public function surfacesADatabaseFailureInsteadOfReportingAnEmptyDrain(): void
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_solr_indexqueue_item');
        $connection->executeStatement('DROP TABLE ' . $connection->quoteIdentifier('tx_solr_indexqueue_item'));

        $this->expectException(Throwable::class);

        $this->buildDrainer($this->recordingIndexer())->drain(10);
    }

    #[Test]
    public function skipsRootsWhereSiteDisablesEagerFlush(): void
    {
        $this->insertPendingItem(root: 1);
        $this->insertPendingItem(root: 2);
        $indexer = $this->recordingIndexer();
        $policy = new class implements SiteEagerFlushPolicy {
            public function isEnabledForRoot(int $rootPageId): bool
            {
                return 2 !== $rootPageId;
            }
        };

        $result = $this->buildDrainer($indexer, $policy)->drain(10);

        self::assertSame([1], $indexer->attempted, 'Root 2 (eager flush disabled for its site) is not indexed');
        self::assertSame([1], $result->succeededRoots);
        self::assertSame([], $result->failedRoots, 'A disabled site is skipped, not counted as a failure');
    }

    #[Test]
    public function skipsRootsWhereSolrIsUnreachable(): void
    {
        $this->insertPendingItem(root: 1);
        $this->insertPendingItem(root: 2);
        $indexer = $this->recordingIndexer();
        $reachability = new class implements SolrReachability {
            public function isReachable(int $rootPageId): bool
            {
                return 2 !== $rootPageId;
            }
        };

        $result = $this->buildDrainer($indexer, reachability: $reachability)->drain(10);

        self::assertSame([1], $indexer->attempted, 'Root 2 (Solr unreachable) is not indexed');
        self::assertSame([1], $result->succeededRoots);
        self::assertSame([], $result->failedRoots, 'An unreachable Solr is skipped, not counted as a failure');
    }

    private function buildDrainer(
        SiteIndexer $indexer,
        ?SiteEagerFlushPolicy $policy = null,
        ?SolrReachability $reachability = null,
        TypeFilterMode $typeFilter = TypeFilterMode::Both,
    ): IndexQueueDrainer {
        return new IndexQueueDrainer(
            GeneralUtility::makeInstance(ConnectionPool::class),
            new PendingItemPredicate(),
            $indexer,
            $policy ?? $this->allEnabledPolicy(),
            $reachability ?? $this->alwaysReachable(),
            new ExtensionConfiguration($typeFilter, indexQueueLimit: 5, deltaMax: 10),
        );
    }

    private function alwaysReachable(): SolrReachability
    {
        return new class implements SolrReachability {
            public function isReachable(int $rootPageId): bool
            {
                return true;
            }
        };
    }

    private function allEnabledPolicy(): SiteEagerFlushPolicy
    {
        return new class implements SiteEagerFlushPolicy {
            public function isEnabledForRoot(int $rootPageId): bool
            {
                return true;
            }
        };
    }

    private function recordingIndexer(): RecordingSiteIndexer
    {
        return new RecordingSiteIndexer();
    }

    private function insertPendingItem(int $root, string $itemType = 'pages'): void
    {
        $now = time();
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_solr_indexqueue_item')
            ->insert('tx_solr_indexqueue_item', [
                'root' => $root,
                'item_type' => $itemType,
                'item_uid' => $root,
                'indexing_configuration' => $itemType,
                'changed' => $now,
                'indexed' => 0,
                'errors' => '',
                'indexing_priority' => 0,
            ]);
    }
}
