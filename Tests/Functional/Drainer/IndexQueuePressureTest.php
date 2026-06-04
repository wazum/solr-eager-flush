<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Tests\Functional\Drainer;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Wazum\SolrEagerFlush\Configuration\ExtensionConfiguration;
use Wazum\SolrEagerFlush\Drainer\IndexQueuePressure;
use Wazum\SolrEagerFlush\Drainer\PendingItemPredicate;
use Wazum\SolrEagerFlush\Tests\Functional\AbstractFunctionalTestCase;
use Wazum\SolrEagerFlush\TypeFilter\TypeFilterMode;

final class IndexQueuePressureTest extends AbstractFunctionalTestCase
{
    public function testUnderLimit(): void
    {
        $this->seedPending(4);

        self::assertTrue($this->pressure(indexQueueLimit: 5)->isUnderLimit(1));
    }

    public function testAtExactlyLimit(): void
    {
        $this->seedPending(5);

        self::assertTrue($this->pressure(indexQueueLimit: 5)->isUnderLimit(1));
    }

    public function testOverLimit(): void
    {
        $this->seedPending(6);

        self::assertFalse($this->pressure(indexQueueLimit: 5)->isUnderLimit(1));
    }

    public function testCountsOnlyTheGivenRoot(): void
    {
        for ($i = 0; $i < 20; ++$i) {
            $this->insertItem(['root' => 2]);
        }

        self::assertTrue($this->pressure(indexQueueLimit: 5)->isUnderLimit(1), 'Another site\'s backlog must not apply');
    }

    public function testIgnoresPagesInRecordsMode(): void
    {
        for ($i = 0; $i < 20; ++$i) {
            $this->insertItem(['item_type' => 'pages']);
        }

        self::assertTrue(
            $this->pressure(indexQueueLimit: 5, mode: TypeFilterMode::Records)->isUnderLimit(1),
            'A page backlog must not suppress the eager flush in records mode',
        );
    }

    public function testCountsRecordsInRecordsMode(): void
    {
        for ($i = 0; $i < 6; ++$i) {
            $this->insertItem(['item_type' => 'tx_news_domain_model_news']);
        }

        self::assertFalse($this->pressure(indexQueueLimit: 5, mode: TypeFilterMode::Records)->isUnderLimit(1));
    }

    public function testIgnoresErroredAndAlreadyIndexedItems(): void
    {
        $now = time();
        for ($i = 0; $i < 10; ++$i) {
            $this->insertItem(['changed' => $now, 'indexed' => 0, 'errors' => 'boom']);
            $this->insertItem(['changed' => $now - 100, 'indexed' => $now, 'errors' => '']);
        }

        self::assertTrue($this->pressure(indexQueueLimit: 1)->isUnderLimit(1));
    }

    private function seedPending(int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $this->insertItem([]);
        }
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

    private function pressure(int $indexQueueLimit, TypeFilterMode $mode = TypeFilterMode::Both): IndexQueuePressure
    {
        return new IndexQueuePressure(
            GeneralUtility::makeInstance(ConnectionPool::class),
            new PendingItemPredicate(),
            new ExtensionConfiguration(
                typeFilter: $mode,
                indexQueueLimit: $indexQueueLimit,
                deltaMax: 10,
            ),
        );
    }
}
