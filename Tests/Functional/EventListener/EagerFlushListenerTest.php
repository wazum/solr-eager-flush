<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Tests\Functional\EventListener;

use ApacheSolrForTypo3\Solr\Domain\Index\Queue\UpdateHandler\EventListener\Events\ProcessingFinishedEvent;
use ApacheSolrForTypo3\Solr\Domain\Index\Queue\UpdateHandler\Events\DataUpdateEventInterface;
use ApacheSolrForTypo3\Solr\Domain\Index\Queue\UpdateHandler\Events\RecordUpdatedEvent;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Wazum\SolrEagerFlush\Drainer\EagerFlushScheduler;
use Wazum\SolrEagerFlush\EventListener\EagerFlushListener;
use Wazum\SolrEagerFlush\Site\SiteRootResolver;
use Wazum\SolrEagerFlush\Tests\Functional\AbstractFunctionalTestCase;

final class EagerFlushListenerTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function schedulesTheResolvedRootForFlushing(): void
    {
        $scheduledRoots = [];
        $listener = $this->buildListener(
            scheduledRoots: $scheduledRoots,
            rootResolver: $this->resolverReturning([42]),
        );

        $listener->__invoke($this->makeFinishedEvent());

        self::assertSame([42], $scheduledRoots, 'The resolved site root must be scheduled for flushing');
    }

    #[Test]
    public function schedulesEveryResponsibleRootForMultiRootRecords(): void
    {
        $scheduledRoots = [];
        $listener = $this->buildListener(
            scheduledRoots: $scheduledRoots,
            rootResolver: $this->resolverReturning([42, 43]),
        );

        $listener->__invoke($this->makeFinishedEvent());

        self::assertSame([42, 43], $scheduledRoots, 'A record responsible for several roots must schedule all of them');
    }

    #[Test]
    public function defersToSchedulerTaskWhenSiteCannotBeResolved(): void
    {
        $scheduledRoots = [];
        $listener = $this->buildListener(
            scheduledRoots: $scheduledRoots,
            rootResolver: $this->resolverReturning([]),
        );

        $listener->__invoke($this->makeFinishedEvent());

        self::assertSame([], $scheduledRoots, 'An unresolved site must defer to the index queue worker, never fan out');
    }

    #[Test]
    public function swallowsExceptionsFromTheResolver(): void
    {
        $scheduledRoots = [];
        $listener = $this->buildListener(
            scheduledRoots: $scheduledRoots,
            rootResolver: new class implements SiteRootResolver {
                public function resolveRootPageIds(DataUpdateEventInterface $event): array
                {
                    throw new RuntimeException('resolver boom');
                }
            },
        );

        $listener->__invoke($this->makeFinishedEvent());

        self::assertSame([], $scheduledRoots, 'A throwing resolver must never break the save');
    }

    /**
     * @param list<int> $scheduledRoots
     */
    private function buildListener(
        array &$scheduledRoots,
        SiteRootResolver $rootResolver,
        LoggerInterface $logger = new NullLogger(),
    ): EagerFlushListener {
        return new EagerFlushListener(
            rootResolver: $rootResolver,
            scheduler: new class($scheduledRoots) extends EagerFlushScheduler {
                /**
                 * @param list<int> $scheduledRoots
                 */
                public function __construct(public array &$scheduledRoots) {}

                public function schedule(int $rootPageId): void
                {
                    $this->scheduledRoots[] = $rootPageId;
                }
            },
            logger: $logger,
        );
    }

    /**
     * @param list<int> $roots
     */
    private function resolverReturning(array $roots): SiteRootResolver
    {
        return new class($roots) implements SiteRootResolver {
            /**
             * @param list<int> $roots
             */
            public function __construct(private readonly array $roots) {}

            public function resolveRootPageIds(DataUpdateEventInterface $event): array
            {
                return $this->roots;
            }
        };
    }

    private function makeFinishedEvent(): ProcessingFinishedEvent
    {
        $recordEvent = new RecordUpdatedEvent(uid: 1, table: 'pages');

        return new ProcessingFinishedEvent($recordEvent);
    }
}
