<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Tests\Functional\Drainer;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Wazum\SolrEagerFlush\Drainer\IndexServiceSiteIndexer;
use Wazum\SolrEagerFlush\Tests\Functional\AbstractFunctionalTestCase;

/**
 * Every other test fakes SiteIndexer, so this is the only coverage of ext:solr's IndexService.
 */
final class IndexServiceSiteIndexerTest extends AbstractFunctionalTestCase
{
    protected array $pathsToProvideInTestInstance = [
        'typo3conf/ext/solr_eager_flush/Tests/Functional/Fixtures/Sites/solreagerflushtest/config.yaml' => 'typo3conf/sites/solreagerflushtest/config.yaml',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/pages.csv');
        $this->setUpFrontendRootPage(1, [
            'constants' => ['EXT:solr/Configuration/TypoScript/Solr/constants.typoscript'],
            'setup' => [
                'EXT:solr/Configuration/TypoScript/Solr/setup.typoscript',
                'EXT:solr_eager_flush/Tests/Functional/Fixtures/TypoScript/record_indexing.typoscript',
            ],
        ]);
    }

    #[Test]
    public function reportsFailureAndRecordsTheErrorWhenSolrRefusesTheConnection(): void
    {
        $itemUid = $this->insertPendingCategoryItem();

        $indexed = $this->get(IndexServiceSiteIndexer::class)->index(1, 10);

        self::assertFalse($indexed, 'An unreachable Solr must be reported as a failed index run');
        self::assertStringContainsString(
            '127.0.0.1:65534',
            $this->errorsOf($itemUid),
            'IndexService must have taken the item this queue selected, built a document from it '
            . 'and failed against the configured Solr, and recorded that on the item',
        );
    }

    private function insertPendingCategoryItem(): int
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class);
        $connection->getConnectionForTable('sys_category')->insert('sys_category', [
            'uid' => 5,
            'pid' => 1,
            'title' => 'Indexable category',
        ]);
        $connection->getConnectionForTable('tx_solr_indexqueue_item')->insert('tx_solr_indexqueue_item', [
            'root' => 1,
            'item_type' => 'sys_category',
            'item_uid' => 5,
            'indexing_configuration' => 'categories',
            'changed' => time() - 100,
            'indexed' => 0,
            'errors' => '',
            'indexing_priority' => 0,
        ]);

        return (int) $connection->getConnectionForTable('tx_solr_indexqueue_item')->lastInsertId();
    }

    private function errorsOf(int $uid): string
    {
        return (string) GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_solr_indexqueue_item')
            ->fetchOne('SELECT errors FROM tx_solr_indexqueue_item WHERE uid = ?', [$uid]);
    }
}
