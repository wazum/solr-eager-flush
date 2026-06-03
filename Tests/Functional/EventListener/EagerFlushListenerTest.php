<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Tests\Functional\EventListener;

use ApacheSolrForTypo3\Solr\Domain\Index\Queue\UpdateHandler\EventListener\Events\ProcessingFinishedEvent;
use ApacheSolrForTypo3\Solr\Domain\Index\Queue\UpdateHandler\Events\DataUpdateEventInterface;
use ApacheSolrForTypo3\Solr\Domain\Index\Queue\UpdateHandler\Events\RecordUpdatedEvent;
use Closure;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use RuntimeException;
use Stringable;
use Wazum\SolrEagerFlush\Configuration\ExtensionConfiguration;
use Wazum\SolrEagerFlush\Drainer\DrainResult;
use Wazum\SolrEagerFlush\Drainer\IndexQueueDrainer;
use Wazum\SolrEagerFlush\EventListener\EagerFlushListener;
use Wazum\SolrEagerFlush\Gate\EagerFlushGate;
use Wazum\SolrEagerFlush\Site\SiteRootResolver;
use Wazum\SolrEagerFlush\Tests\Functional\AbstractFunctionalTestCase;
use Wazum\SolrEagerFlush\TypeFilter\TypeFilterMode;

final class EagerFlushListenerTest extends AbstractFunctionalTestCase
{
    public function testSkipsWhenFirstGateReturnsFalse(): void
    {
        $drainerCalled = false;
        $listener = $this->buildListener(
            gates: [$this->gate(false), $this->gate(true)],
            onDrain: static function () use (&$drainerCalled): void {
                $drainerCalled = true;
            },
        );

        $listener->__invoke($this->makeFinishedEvent());

        self::assertFalse($drainerCalled, 'Drainer must not run when a gate skips');
    }

    public function testDrainsWhenAllGatesPass(): void
    {
        $drainerCalled = false;
        $listener = $this->buildListener(
            gates: [$this->gate(true), $this->gate(true)],
            onDrain: static function () use (&$drainerCalled): void {
                $drainerCalled = true;
            },
        );

        $listener->__invoke($this->makeFinishedEvent());

        self::assertTrue($drainerCalled, 'Drainer must run when all gates pass');
    }

    public function testSwallowsExceptionsFromGate(): void
    {
        $throwingGate = new class implements EagerFlushGate {
            public function shouldProceed(): bool
            {
                throw new RuntimeException('boom');
            }
        };
        $drainerCalled = false;
        $listener = $this->buildListener(
            gates: [$throwingGate],
            onDrain: static function () use (&$drainerCalled): void {
                $drainerCalled = true;
            },
        );

        $listener->__invoke($this->makeFinishedEvent());

        self::assertFalse($drainerCalled, 'Drainer untouched after gate exception');
    }

    public function testSwallowsExceptionsFromDrainer(): void
    {
        $drainerCalled = false;
        $listener = $this->buildListener(
            gates: [$this->gate(true)],
            onDrain: static function () use (&$drainerCalled): void {
                $drainerCalled = true;
                throw new RuntimeException('drain boom');
            },
        );

        $listener->__invoke($this->makeFinishedEvent());

        self::assertTrue($drainerCalled, 'Drainer was called (and threw, but exception was swallowed)');
    }

    public function testPassesResolvedSiteRootToDrainer(): void
    {
        $capturedRoot = -1;
        $listener = $this->buildListener(
            gates: [$this->gate(true)],
            onDrain: static function (int $deltaMax, ?int $onlyRootPageId) use (&$capturedRoot): void {
                $capturedRoot = $onlyRootPageId;
            },
            rootResolver: $this->resolverReturning(42),
        );

        $listener->__invoke($this->makeFinishedEvent());

        self::assertSame(42, $capturedRoot, 'Listener must scope the drain to the resolved site root');
    }

    public function testLogsWarningWhenDrainReportsFailures(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $levels = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->levels[] = (string) $level;
            }
        };
        $listener = $this->buildListener(
            gates: [$this->gate(true)],
            onDrain: static function (): void {},
            result: new DrainResult(succeededRoots: [1], failedRoots: [2]),
            logger: $logger,
        );

        $listener->__invoke($this->makeFinishedEvent());

        self::assertContains(LogLevel::WARNING, $logger->levels, 'A drain with failures must be logged as a warning');
        self::assertNotContains(LogLevel::INFO, $logger->levels);
    }

    /**
     * @param list<EagerFlushGate> $gates
     */
    private function buildListener(
        array $gates,
        Closure $onDrain,
        DrainResult $result = new DrainResult(),
        LoggerInterface $logger = new NullLogger(),
        ?SiteRootResolver $rootResolver = null,
    ): EagerFlushListener {
        return new EagerFlushListener(
            gates: $gates,
            drainer: new class($onDrain, $result) extends IndexQueueDrainer {
                public function __construct(
                    private readonly Closure $onDrain,
                    private readonly DrainResult $result,
                ) {}

                public function drain(int $deltaMax, ?int $onlyRootPageId = null): DrainResult
                {
                    ($this->onDrain)($deltaMax, $onlyRootPageId);

                    return $this->result;
                }
            },
            rootResolver: $rootResolver ?? $this->resolverReturning(null),
            config: new ExtensionConfiguration(
                typeFilter: TypeFilterMode::Both,
                indexQueueLimit: 5,
                deltaMax: 10,
            ),
            logger: $logger,
        );
    }

    private function gate(bool $proceed): EagerFlushGate
    {
        return new class($proceed) implements EagerFlushGate {
            public function __construct(private readonly bool $proceed) {}

            public function shouldProceed(): bool
            {
                return $this->proceed;
            }
        };
    }

    private function resolverReturning(?int $root): SiteRootResolver
    {
        return new class($root) implements SiteRootResolver {
            public function __construct(private readonly ?int $root) {}

            public function resolveRootPageId(DataUpdateEventInterface $event): ?int
            {
                return $this->root;
            }
        };
    }

    private function makeFinishedEvent(): ProcessingFinishedEvent
    {
        $recordEvent = new RecordUpdatedEvent(uid: 1, table: 'pages');

        return new ProcessingFinishedEvent($recordEvent);
    }
}
