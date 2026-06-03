<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Tests\Functional\EventListener;

use ApacheSolrForTypo3\Solr\Domain\Index\Queue\UpdateHandler\EventListener\Events\ProcessingFinishedEvent;
use ApacheSolrForTypo3\Solr\Domain\Index\Queue\UpdateHandler\Events\RecordUpdatedEvent;
use Closure;
use Psr\Log\NullLogger;
use RuntimeException;
use Wazum\SolrEagerFlush\Configuration\ExtensionConfiguration;
use Wazum\SolrEagerFlush\Drainer\IndexQueueDrainer;
use Wazum\SolrEagerFlush\EventListener\EagerFlushListener;
use Wazum\SolrEagerFlush\Gate\EagerFlushGate;
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

    /**
     * @param list<EagerFlushGate> $gates
     */
    private function buildListener(
        array $gates,
        Closure $onDrain,
    ): EagerFlushListener {
        return new EagerFlushListener(
            gates: $gates,
            drainer: new class($onDrain) extends IndexQueueDrainer {
                public function __construct(private readonly Closure $onDrain) {}

                public function drain(int $deltaMax): void
                {
                    ($this->onDrain)($deltaMax);
                }
            },
            config: new ExtensionConfiguration(
                typeFilter: TypeFilterMode::Both,
                indexQueueLimit: 5,
                deltaMax: 10,
            ),
            logger: new NullLogger(),
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

    private function makeFinishedEvent(): ProcessingFinishedEvent
    {
        $recordEvent = new RecordUpdatedEvent(uid: 1, table: 'pages');

        return new ProcessingFinishedEvent($recordEvent);
    }
}
