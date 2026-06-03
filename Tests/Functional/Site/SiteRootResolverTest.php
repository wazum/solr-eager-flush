<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Tests\Functional\Site;

use ApacheSolrForTypo3\Solr\Domain\Index\Queue\UpdateHandler\Events\RecordUpdatedEvent;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use RuntimeException;
use Stringable;
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
        $root = $this->resolver($this->siteFinderReturningRoot(7))
            ->resolveRootPageId(new RecordUpdatedEvent(uid: 10, table: 'pages'));

        self::assertSame(7, $root);
    }

    public function testResolvesSiteRootForRecordViaItsPid(): void
    {
        $this->connectionPool()
            ->getConnectionForTable('tt_content')
            ->insert('tt_content', ['uid' => 500, 'pid' => 10]);

        $root = $this->resolver($this->siteFinderReturningRoot(7))
            ->resolveRootPageId(new RecordUpdatedEvent(uid: 500, table: 'tt_content'));

        self::assertSame(7, $root);
    }

    public function testReturnsNullSilentlyWhenPageHasNoSite(): void
    {
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getSiteByPageId')->willThrowException(new SiteNotFoundException('no site', 1));
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $levels = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->levels[] = (string) $level;
            }
        };

        $root = $this->resolver($siteFinder, $logger)->resolveRootPageId(new RecordUpdatedEvent(uid: 9999, table: 'pages'));

        self::assertNull($root);
        self::assertSame([], $logger->levels, 'A page without a site is expected and must not be logged');
    }

    public function testReturnsNullWhenRecordHasNoRow(): void
    {
        $root = $this->resolver($this->siteFinderReturningRoot(7))
            ->resolveRootPageId(new RecordUpdatedEvent(uid: 12345, table: 'tt_content'));

        self::assertNull($root);
    }

    public function testLogsAndReturnsNullOnUnexpectedError(): void
    {
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getSiteByPageId')->willThrowException(new RuntimeException('database is down'));
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $levels = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->levels[] = (string) $level;
            }
        };

        $root = $this->resolver($siteFinder, $logger)->resolveRootPageId(new RecordUpdatedEvent(uid: 10, table: 'pages'));

        self::assertNull($root);
        self::assertContains(LogLevel::WARNING, $logger->levels, 'An unexpected resolution error must be logged');
    }

    private function resolver(SiteFinder $siteFinder, LoggerInterface $logger = new NullLogger()): CoreSiteRootResolver
    {
        return new CoreSiteRootResolver($siteFinder, $this->connectionPool(), $logger);
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
