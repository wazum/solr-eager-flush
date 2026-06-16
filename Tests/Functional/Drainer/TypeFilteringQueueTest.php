<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Tests\Functional\Drainer;

use ApacheSolrForTypo3\Solr\Domain\Site\Site;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Wazum\SolrEagerFlush\Drainer\PendingItemPredicate;
use Wazum\SolrEagerFlush\Drainer\TypeFilteringQueue;
use Wazum\SolrEagerFlush\Tests\Functional\AbstractFunctionalTestCase;
use Wazum\SolrEagerFlush\TypeFilter\TypeFilterMode;

final class TypeFilteringQueueTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function recordsModeSelectsRecordsEvenWhenNewerPagesWouldFillTheWindow(): void
    {
        // Newer 'pages' items would fill ext:solr's own (priority/changed-ordered) window first
        for ($i = 0; $i < 6; ++$i) {
            $this->insertItem(['item_type' => 'pages', 'changed' => time()]);
        }
        $this->insertItem(['item_type' => 'tx_news_domain_model_news', 'changed' => time() - 100]);

        $items = $this->queue(TypeFilterMode::Records)->getItemsToIndex($this->siteWithRoot(1), 5);

        self::assertCount(1, $items, 'The eligible record must not be starved by excluded items');
        self::assertSame('tx_news_domain_model_news', $items[0]->getType());
    }

    #[Test]
    public function pagesModeSelectsOnlyPages(): void
    {
        $this->insertItem(['item_type' => 'pages']);
        $this->insertItem(['item_type' => 'tx_news_domain_model_news']);

        $items = $this->queue(TypeFilterMode::Pages)->getItemsToIndex($this->siteWithRoot(1), 10);

        self::assertCount(1, $items);
        self::assertSame('pages', $items[0]->getType());
    }

    #[Test]
    public function bothModeSelectsEveryType(): void
    {
        $this->insertItem(['item_type' => 'pages']);
        $this->insertItem(['item_type' => 'tx_news_domain_model_news']);

        $items = $this->queue(TypeFilterMode::Both)->getItemsToIndex($this->siteWithRoot(1), 10);

        self::assertCount(2, $items);
    }

    private function queue(TypeFilterMode $mode): TypeFilteringQueue
    {
        return new TypeFilteringQueue(
            GeneralUtility::makeInstance(ConnectionPool::class),
            new PendingItemPredicate(),
            $mode,
        );
    }

    private function siteWithRoot(int $rootPageId): Site
    {
        $site = $this->createStub(Site::class);
        $site->method('getRootPageId')->willReturn($rootPageId);

        return $site;
    }

    /**
     * @param array<string, int|string> $overrides
     */
    private function insertItem(array $overrides): void
    {
        $now = time();
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_solr_indexqueue_item')
            ->insert('tx_solr_indexqueue_item', array_merge([
                'root' => 1,
                'item_type' => 'pages',
                'item_uid' => random_int(1000, 99999),
                'indexing_configuration' => 'pages',
                'changed' => $now,
                'indexed' => 0,
                'errors' => '',
                'indexing_priority' => 0,
            ], $overrides));
    }
}
