<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Tests\Functional\Gate;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Wazum\SolrEagerFlush\Configuration\ExtensionConfiguration;
use Wazum\SolrEagerFlush\Drainer\PendingItemPredicate;
use Wazum\SolrEagerFlush\Gate\IndexQueuePressureGate;
use Wazum\SolrEagerFlush\Tests\Functional\AbstractFunctionalTestCase;
use Wazum\SolrEagerFlush\TypeFilter\TypeFilterMode;

final class IndexQueuePressureGateTest extends AbstractFunctionalTestCase
{
    public function testProceedsBelowLimit(): void
    {
        $this->seedPendingItems(4);

        self::assertTrue($this->buildGate(indexQueueLimit: 5)->shouldProceed());
    }

    public function testProceedsAtExactlyLimit(): void
    {
        $this->seedPendingItems(5);

        self::assertTrue($this->buildGate(indexQueueLimit: 5)->shouldProceed());
    }

    public function testSkipsAboveLimit(): void
    {
        $this->seedPendingItems(6);

        self::assertFalse($this->buildGate(indexQueueLimit: 5)->shouldProceed());
    }

    public function testIgnoresAlreadyIndexedItems(): void
    {
        $now = time();
        for ($i = 0; $i < 10; ++$i) {
            $this->insertItem(['changed' => $now - 100, 'indexed' => $now, 'errors' => '']);
        }

        self::assertTrue($this->buildGate(indexQueueLimit: 1)->shouldProceed());
    }

    public function testIgnoresErroredItems(): void
    {
        $now = time();
        for ($i = 0; $i < 10; ++$i) {
            $this->insertItem(['changed' => $now, 'indexed' => 0, 'errors' => 'failure']);
        }

        self::assertTrue($this->buildGate(indexQueueLimit: 1)->shouldProceed());
    }

    public function testIgnoresFutureChangedItems(): void
    {
        $now = time();
        for ($i = 0; $i < 10; ++$i) {
            $this->insertItem(['changed' => $now + 3600, 'indexed' => 0, 'errors' => '']);
        }

        self::assertTrue($this->buildGate(indexQueueLimit: 1)->shouldProceed());
    }

    private function seedPendingItems(int $count): void
    {
        $now = time();
        for ($i = 0; $i < $count; ++$i) {
            $this->insertItem(['changed' => $now, 'indexed' => 0, 'errors' => '']);
        }
    }

    /**
     * @param array<string, int|string> $overrides
     */
    private function insertItem(array $overrides): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_solr_indexqueue_item')
            ->insert('tx_solr_indexqueue_item', array_merge([
                'root' => 1,
                'item_type' => 'pages',
                'item_uid' => random_int(1000, 99999),
                'indexing_configuration' => 'pages',
                'indexing_priority' => 0,
            ], $overrides));
    }

    private function buildGate(int $indexQueueLimit): IndexQueuePressureGate
    {
        return new IndexQueuePressureGate(
            GeneralUtility::makeInstance(ConnectionPool::class),
            new PendingItemPredicate(),
            new ExtensionConfiguration(
                typeFilter: TypeFilterMode::Both,
                indexQueueLimit: $indexQueueLimit,
                deltaMax: 10,
            ),
        );
    }
}
