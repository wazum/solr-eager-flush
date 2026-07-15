<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Tests\Unit\Site;

use ApacheSolrForTypo3\Solr\Domain\Index\Queue\RecordMonitor\Exception\RootPageRecordNotFoundException;
use ApacheSolrForTypo3\Solr\Domain\Index\Queue\RecordMonitor\Helper\RootPageResolver;
use ApacheSolrForTypo3\Solr\Domain\Index\Queue\UpdateHandler\Events\RecordUpdatedEvent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use RuntimeException;
use Stringable;
use Wazum\SolrEagerFlush\Site\CoreSiteRootResolver;

final class SiteRootResolverTest extends TestCase
{
    #[Test]
    public function returnsEveryResponsibleRoot(): void
    {
        $resolver = $this->resolver($this->rootPageResolverReturning([7, 12]));

        self::assertSame(
            [7, 12],
            $resolver->resolveRootPageIds(new RecordUpdatedEvent(uid: 10, table: 'pages')),
            'A record responsible for several roots must flush all of them, not just one',
        );
    }

    #[Test]
    public function deduplicatesRepeatedRoots(): void
    {
        $resolver = $this->resolver($this->rootPageResolverReturning([7, 7, 12]));

        self::assertSame([7, 12], $resolver->resolveRootPageIds(new RecordUpdatedEvent(uid: 500, table: 'tt_content')));
    }

    #[Test]
    public function returnsEmptySilentlyWhenNoRootPageRecordExists(): void
    {
        $rootPageResolver = $this->createMock(RootPageResolver::class);
        $rootPageResolver->method('getResponsibleRootPageIds')
            ->willThrowException(new RootPageRecordNotFoundException('no page', 1));
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $levels = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->levels[] = (string) $level;
            }
        };

        $roots = $this->resolver($rootPageResolver, $logger)
            ->resolveRootPageIds(new RecordUpdatedEvent(uid: 9999, table: 'pages'));

        self::assertSame([], $roots);
        self::assertSame([], $logger->levels, 'A record without a resolvable root is expected and must not be logged');
    }

    #[Test]
    public function logsAndReturnsEmptyOnUnexpectedError(): void
    {
        $rootPageResolver = $this->createMock(RootPageResolver::class);
        $rootPageResolver->method('getResponsibleRootPageIds')
            ->willThrowException(new RuntimeException('database is down'));
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $levels = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->levels[] = (string) $level;
            }
        };

        $roots = $this->resolver($rootPageResolver, $logger)
            ->resolveRootPageIds(new RecordUpdatedEvent(uid: 10, table: 'pages'));

        self::assertSame([], $roots);
        self::assertContains(LogLevel::WARNING, $logger->levels, 'An unexpected resolution error must be logged');
    }

    private function resolver(
        RootPageResolver $rootPageResolver,
        LoggerInterface $logger = new NullLogger(),
    ): CoreSiteRootResolver {
        return new CoreSiteRootResolver($rootPageResolver, $logger);
    }

    /**
     * @param list<int> $roots
     */
    private function rootPageResolverReturning(array $roots): RootPageResolver
    {
        $rootPageResolver = $this->createMock(RootPageResolver::class);
        $rootPageResolver->method('getResponsibleRootPageIds')->willReturn($roots);

        return $rootPageResolver;
    }
}
