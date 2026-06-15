<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Tests\Unit\Drainer;

use Closure;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wazum\SolrEagerFlush\Drainer\EagerFlushRunner;
use Wazum\SolrEagerFlush\Drainer\EagerFlushScheduler;
use Wazum\SolrEagerFlush\Runtime\DeferredExecution;
use Wazum\SolrEagerFlush\Runtime\ResponseDetacher;

final class EagerFlushSchedulerTest extends TestCase
{
    #[Test]
    public function runsTheFlushImmediatelyWhenDeferralIsUnsupported(): void
    {
        $runRoots = [];
        $scheduler = $this->buildScheduler(
            deferralSupported: false,
            runRoots: $runRoots,
        );

        $scheduler->schedule(7);

        self::assertSame([7], $runRoots, 'Without deferral support the flush must run synchronously');
    }

    #[Test]
    public function defersTheFlushToTheEndOfTheRequestWhenSupported(): void
    {
        $runRoots = [];
        $deferredTask = null;
        $scheduler = $this->buildScheduler(
            deferralSupported: true,
            runRoots: $runRoots,
            onDefer: static function (callable $task) use (&$deferredTask): void {
                $deferredTask = $task;
            },
        );

        $scheduler->schedule(7);

        self::assertSame([], $runRoots, 'Nothing must run while the editor is still waiting for the response');
        self::assertIsCallable($deferredTask, 'A deferred task must be registered');

        $deferredTask();

        self::assertSame([7], $runRoots, 'The deferred task must flush the scheduled root');
    }

    #[Test]
    public function collapsesManySavesIntoOneDeferredTaskAndFlushesEachRootOnce(): void
    {
        $runRoots = [];
        $deferredTasks = [];
        $scheduler = $this->buildScheduler(
            deferralSupported: true,
            runRoots: $runRoots,
            onDefer: static function (callable $task) use (&$deferredTasks): void {
                $deferredTasks[] = $task;
            },
        );

        $scheduler->schedule(7);
        $scheduler->schedule(7);
        $scheduler->schedule(9);

        self::assertCount(1, $deferredTasks, 'All saves of one request must share a single deferred task');

        $deferredTasks[0]();

        self::assertSame([7, 9], $runRoots, 'Each affected root must be flushed exactly once');
    }

    #[Test]
    public function detachesTheResponseBeforeDraining(): void
    {
        $runRoots = [];
        $deferredTask = null;
        $events = [];
        $scheduler = $this->buildScheduler(
            deferralSupported: true,
            runRoots: $runRoots,
            onDefer: static function (callable $task) use (&$deferredTask): void {
                $deferredTask = $task;
            },
            events: $events,
        );

        $scheduler->schedule(7);
        self::assertIsCallable($deferredTask);
        $deferredTask();

        self::assertSame(['detach', 'run:7'], $events, 'The editor must be released before any indexing starts');
    }

    #[Test]
    public function aSecondFlushDoesNotDrainTheSameRootsAgain(): void
    {
        $runRoots = [];
        $deferredTask = null;
        $scheduler = $this->buildScheduler(
            deferralSupported: true,
            runRoots: $runRoots,
            onDefer: static function (callable $task) use (&$deferredTask): void {
                $deferredTask = $task;
            },
        );

        $scheduler->schedule(7);
        self::assertIsCallable($deferredTask);
        $deferredTask();
        $deferredTask();

        self::assertSame([7], $runRoots, 'A flushed root must not be drained a second time');
    }

    /**
     * @param list<int> $runRoots
     * @param list<string>|null $events
     */
    private function buildScheduler(
        bool $deferralSupported,
        array &$runRoots,
        ?Closure $onDefer = null,
        bool $detached = true,
        ?array &$events = null,
    ): EagerFlushScheduler {
        return new EagerFlushScheduler(
            deferredExecution: new class($deferralSupported, $onDefer) implements DeferredExecution {
                public function __construct(
                    private readonly bool $supported,
                    private readonly ?Closure $onDefer,
                ) {}

                public function isSupported(): bool
                {
                    return $this->supported;
                }

                public function defer(callable $task): void
                {
                    if (null !== $this->onDefer) {
                        ($this->onDefer)($task);
                    }
                }
            },
            responseDetacher: new class($detached, $events) implements ResponseDetacher {
                /**
                 * @param list<string>|null $events
                 */
                public function __construct(
                    private readonly bool $detached,
                    private ?array &$events,
                ) {}

                public function detach(): bool
                {
                    if (null !== $this->events) {
                        $this->events[] = 'detach';
                    }

                    return $this->detached;
                }
            },
            runner: new class($runRoots, $events) extends EagerFlushRunner {
                /**
                 * @param list<int> $runRoots
                 * @param list<string>|null $events
                 */
                public function __construct(
                    public array &$runRoots,
                    public ?array &$events,
                ) {}

                public function run(int $rootPageId): void
                {
                    $this->runRoots[] = $rootPageId;
                    if (null !== $this->events) {
                        $this->events[] = 'run:' . $rootPageId;
                    }
                }
            },
        );
    }
}
