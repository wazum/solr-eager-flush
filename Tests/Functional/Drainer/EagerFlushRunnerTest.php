<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Tests\Functional\Drainer;

use Closure;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use RuntimeException;
use TYPO3\CMS\Core\Locking\Exception\LockAcquireWouldBlockException;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;
use Wazum\SolrEagerFlush\Configuration\ExtensionConfiguration;
use Wazum\SolrEagerFlush\Drainer\DrainResult;
use Wazum\SolrEagerFlush\Drainer\EagerFlushRunner;
use Wazum\SolrEagerFlush\Drainer\IndexQueueDrainer;
use Wazum\SolrEagerFlush\Drainer\IndexQueuePressure;
use Wazum\SolrEagerFlush\Gate\EagerFlushGate;
use Wazum\SolrEagerFlush\Tests\Functional\AbstractFunctionalTestCase;
use Wazum\SolrEagerFlush\TypeFilter\TypeFilterMode;

final class EagerFlushRunnerTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function isWiredWithEveryDependency(): void
    {
        self::assertInstanceOf(
            EagerFlushRunner::class,
            $this->get(EagerFlushRunner::class),
            'A dependency the container cannot autowire would disable the extension silently',
        );
    }

    #[Test]
    public function skipsWhenAGateBlocks(): void
    {
        $drainerCalled = false;
        $logger = new RecordingLogger();
        $runner = $this->buildRunner(
            gates: [$this->gate(false), $this->gate(true)],
            onDrain: static function () use (&$drainerCalled): void {
                $drainerCalled = true;
            },
            logger: $logger,
        );

        $runner->run(1);

        self::assertFalse($drainerCalled, 'Drainer must not run when a gate blocks');
        self::assertSame(['eager-flush skipped'], $logger->messages);
        self::assertSame('gate', $logger->contexts[0]['reason']);
        self::assertSame(1, $logger->contexts[0]['root']);
    }

    #[Test]
    public function drainsTheGivenRootWhenAllGatesPass(): void
    {
        $capturedRoot = -1;
        $runner = $this->buildRunner(
            gates: [$this->gate(true), $this->gate(true)],
            onDrain: static function (int $deltaMax, ?int $onlyRootPageId) use (&$capturedRoot): void {
                $capturedRoot = $onlyRootPageId;
            },
        );

        $runner->run(42);

        self::assertSame(42, $capturedRoot, 'Runner must drain exactly the given site root');
    }

    #[Test]
    public function skipsWhenQueuePressureIsTooHigh(): void
    {
        $drainerCalled = false;
        $logger = new RecordingLogger();
        $runner = $this->buildRunner(
            gates: [$this->gate(true)],
            onDrain: static function () use (&$drainerCalled): void {
                $drainerCalled = true;
            },
            logger: $logger,
            underPressureLimit: false,
        );

        $runner->run(1);

        self::assertFalse($drainerCalled, 'Drainer must not run when the site is over the pressure limit');
        self::assertSame(['eager-flush skipped'], $logger->messages);
        self::assertSame('queue-pressure', $logger->contexts[0]['reason']);
        self::assertSame(5, $logger->contexts[0]['limit'], 'The limit the flush backed off from must be visible');
    }

    #[Test]
    public function reportsASkipInsteadOfCompletionWhenNothingWasIndexed(): void
    {
        $logger = new RecordingLogger();
        $runner = $this->buildRunner(
            gates: [$this->gate(true)],
            onDrain: static function (): void {},
            logger: $logger,
        );

        $runner->run(7);

        self::assertSame(['eager-flush skipped'], $logger->messages, 'A drain that indexed nothing must not read as completed');
        self::assertSame([LogLevel::INFO], $logger->levels);
        self::assertSame('no-pending-items', $logger->contexts[0]['reason']);
        self::assertSame(7, $logger->contexts[0]['root']);
    }

    #[Test]
    public function namesTheSkippedSitesWhenNoRootCouldBeFlushed(): void
    {
        $logger = new RecordingLogger();
        $runner = $this->buildRunner(
            gates: [$this->gate(true)],
            onDrain: static function (): void {},
            result: new DrainResult(skippedRoots: [7 => 'solr-unreachable']),
            logger: $logger,
        );

        $runner->run(7);

        self::assertSame(['eager-flush skipped'], $logger->messages);
        self::assertSame('sites-skipped', $logger->contexts[0]['reason']);
        self::assertSame([7 => 'solr-unreachable'], $logger->contexts[0]['skipped']);
    }

    #[Test]
    public function reportsCompletionOnlyWhenARootWasIndexed(): void
    {
        $logger = new RecordingLogger();
        $runner = $this->buildRunner(
            gates: [$this->gate(true)],
            onDrain: static function (): void {},
            result: new DrainResult(succeededRoots: [7]),
            logger: $logger,
        );

        $runner->run(7);

        self::assertSame(['eager-flush completed'], $logger->messages);
        self::assertSame([LogLevel::INFO], $logger->levels);
        self::assertSame([7], $logger->contexts[0]['succeeded']);
    }

    #[Test]
    public function skipsWhenAnotherFlushAlreadyHoldsTheSiteLock(): void
    {
        $drainerCalled = false;
        $logger = new RecordingLogger();
        $runner = $this->buildRunner(
            gates: [$this->gate(true)],
            onDrain: static function () use (&$drainerCalled): void {
                $drainerCalled = true;
            },
            logger: $logger,
            locker: $this->locker(contended: true),
        );

        $runner->run(7);

        self::assertFalse($drainerCalled, 'Two processes must not index one site at the same time');
        self::assertSame(['eager-flush skipped'], $logger->messages);
        self::assertSame('already-flushing', $logger->contexts[0]['reason']);
        self::assertSame(7, $logger->contexts[0]['root']);
    }

    #[Test]
    public function releasesTheSiteLockAfterFlushing(): void
    {
        $locker = $this->locker();
        $runner = $this->buildRunner(
            gates: [$this->gate(true)],
            onDrain: static function (): void {},
            locker: $locker,
        );

        $runner->run(7);

        self::assertTrue($locker->released, 'A held lock would block every later flush of the site');
    }

    #[Test]
    public function releasesTheSiteLockWhenTheDrainThrows(): void
    {
        $locker = $this->locker();
        $logger = new RecordingLogger();
        $runner = $this->buildRunner(
            gates: [$this->gate(true)],
            onDrain: static function (): void {
                throw new RuntimeException('drain boom');
            },
            logger: $logger,
            locker: $locker,
        );

        $runner->run(7);

        self::assertTrue($locker->released, 'A failing flush must not leave the site locked');
        self::assertContains(LogLevel::ERROR, $logger->levels);
    }

    #[Test]
    public function logsWarningWhenDrainReportsFailures(): void
    {
        $logger = new RecordingLogger();
        $runner = $this->buildRunner(
            gates: [$this->gate(true)],
            onDrain: static function (): void {},
            result: new DrainResult(succeededRoots: [1], failedRoots: [2]),
            logger: $logger,
        );

        $runner->run(1);

        self::assertContains(LogLevel::WARNING, $logger->levels, 'A drain with failures must be logged as a warning');
        self::assertNotContains(LogLevel::INFO, $logger->levels);
    }

    #[Test]
    public function swallowsExceptionsAndLogsThemAsError(): void
    {
        $logger = new RecordingLogger();
        $runner = $this->buildRunner(
            gates: [$this->gate(true)],
            onDrain: static function (): void {
                throw new RuntimeException('drain boom');
            },
            logger: $logger,
        );

        $runner->run(1);

        self::assertContains(LogLevel::ERROR, $logger->levels, 'A throwing drain must be logged, never escape the runner');
    }

    #[Test]
    public function logsScalarExceptionDetailsWhenTheDrainThrows(): void
    {
        $logger = new RecordingLogger();
        $runner = $this->buildRunner(
            gates: [$this->gate(true)],
            onDrain: static function (): void {
                throw new RuntimeException('drain boom', 1234);
            },
            logger: $logger,
        );

        $runner->run(1);

        $errorIndex = array_search(LogLevel::ERROR, $logger->levels, true);
        self::assertIsInt($errorIndex);
        $errorContext = $logger->contexts[$errorIndex];
        self::assertSame(RuntimeException::class, $errorContext['exception_class']);
        self::assertSame('drain boom', $errorContext['exception_message']);
        self::assertSame(1234, $errorContext['exception_code']);
        self::assertStringContainsString('EagerFlushRunnerTest.php:', $errorContext['location']);
    }

    /**
     * @param list<EagerFlushGate> $gates
     */
    private function buildRunner(
        array $gates,
        Closure $onDrain,
        DrainResult $result = new DrainResult(),
        LoggerInterface $logger = new NullLogger(),
        bool $underPressureLimit = true,
        ?LockingStrategyInterface $locker = null,
    ): EagerFlushRunner {
        return new EagerFlushRunner(
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
            pressure: new class($underPressureLimit) extends IndexQueuePressure {
                public function __construct(private readonly bool $underLimit) {}

                public function isUnderLimit(int $rootPageId): bool
                {
                    return $this->underLimit;
                }
            },
            configuration: new ExtensionConfiguration(
                typeFilter: TypeFilterMode::Both,
                indexQueueLimit: 5,
                deltaMax: 10,
            ),
            logger: $logger,
            lockFactory: new class($locker ?? $this->locker()) extends LockFactory {
                public function __construct(private readonly LockingStrategyInterface $locker) {}

                public function createLocker(string $id, int $capabilities = LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE): LockingStrategyInterface
                {
                    return $this->locker;
                }
            },
        );
    }

    /**
     * @return LockingStrategyInterface&object{released: bool}
     */
    private function locker(bool $contended = false): LockingStrategyInterface
    {
        $locker = new class('solr-eager-flush-test') implements LockingStrategyInterface {
            public bool $released = false;

            public bool $contended = false;

            public function __construct($subject) {}

            public static function getCapabilities(): int
            {
                return self::LOCK_CAPABILITY_EXCLUSIVE | self::LOCK_CAPABILITY_NOBLOCK;
            }

            public static function getPriority(): int
            {
                return 0;
            }

            public function acquire($mode = self::LOCK_CAPABILITY_EXCLUSIVE): bool
            {
                if ($this->contended) {
                    throw new LockAcquireWouldBlockException('held elsewhere', 1428700748);
                }

                return true;
            }

            public function release(): bool
            {
                $this->released = true;

                return true;
            }

            public function destroy(): void {}

            public function isAcquired(): bool
            {
                return !$this->contended;
            }
        };
        $locker->contended = $contended;

        return $locker;
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
}
