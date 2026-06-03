<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Tests\Functional\Site;

use ApacheSolrForTypo3\Solr\Domain\Index\Queue\UpdateHandler\Events\RecordUpdatedEvent;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Wazum\SolrEagerFlush\Site\CoreSiteRootResolver;
use Wazum\SolrEagerFlush\Tests\Functional\AbstractFunctionalTestCase;

final class SiteRootResolverTest extends AbstractFunctionalTestCase
{
    public function testResolvesSiteRootForPageUpdate(): void
    {
        $resolver = new CoreSiteRootResolver($this->siteFinderReturningRoot(7), $this->connectionPool());

        $root = $resolver->resolveRootPageId(new RecordUpdatedEvent(uid: 10, table: 'pages'));

        self::assertSame(7, $root);
    }

    public function testResolvesSiteRootForRecordViaItsPid(): void
    {
        $this->connectionPool()
            ->getConnectionForTable('tt_content')
            ->insert('tt_content', ['uid' => 500, 'pid' => 10]);
        $resolver = new CoreSiteRootResolver($this->siteFinderReturningRoot(7), $this->connectionPool());

        $root = $resolver->resolveRootPageId(new RecordUpdatedEvent(uid: 500, table: 'tt_content'));

        self::assertSame(7, $root);
    }

    public function testReturnsNullWhenSiteCannotBeResolved(): void
    {
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getSiteByPageId')->willThrowException(new SiteNotFoundException('no site', 1));
        $resolver = new CoreSiteRootResolver($siteFinder, $this->connectionPool());

        $root = $resolver->resolveRootPageId(new RecordUpdatedEvent(uid: 9999, table: 'pages'));

        self::assertNull($root);
    }

    public function testReturnsNullWhenRecordHasNoRow(): void
    {
        $resolver = new CoreSiteRootResolver($this->siteFinderReturningRoot(7), $this->connectionPool());

        $root = $resolver->resolveRootPageId(new RecordUpdatedEvent(uid: 12345, table: 'tt_content'));

        self::assertNull($root);
    }

    private function siteFinderReturningRoot(int $rootPageId): SiteFinder
    {
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getSiteByPageId')->willReturn(new Site('test', $rootPageId, ['base' => '/']));

        return $siteFinder;
    }

    private function connectionPool(): ConnectionPool
    {
        return GeneralUtility::makeInstance(ConnectionPool::class);
    }
}
