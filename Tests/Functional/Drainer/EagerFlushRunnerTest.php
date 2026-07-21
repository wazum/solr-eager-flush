<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Tests\Functional\Drainer;

use Closure;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use RuntimeException;
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
    public function skipsWhenAGateBlocks(): void
    {
        $drainerCalled = false;
        $runner = $this->buildRunner(
            gates: [$this->gate(false), $this->gate(true)],
            onDrain: static function () use (&$drainerCalled): void {
                $drainerCalled = true;
            },
        );

        $runner->run(1);

        self::assertFalse($drainerCalled, 'Drainer must not run when a gate blocks');
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
        $runner = $this->buildRunner(
            gates: [$this->gate(true)],
            onDrain: static function () use (&$drainerCalled): void {
                $drainerCalled = true;
            },
            underPressureLimit: false,
        );

        $runner->run(1);

        self::assertFalse($drainerCalled, 'Drainer must not run when the site is over the pressure limit');
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
}
