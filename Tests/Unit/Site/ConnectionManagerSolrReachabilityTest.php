<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Tests\Unit\Site;

use ApacheSolrForTypo3\Solr\ConnectionManager;
use ApacheSolrForTypo3\Solr\System\Solr\Service\SolrWriteService;
use ApacheSolrForTypo3\Solr\System\Solr\SolrConnection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use RuntimeException;
use Wazum\SolrEagerFlush\Site\ConnectionManagerSolrReachability;
use Wazum\SolrEagerFlush\Tests\Functional\Drainer\RecordingLogger;

final class ConnectionManagerSolrReachabilityTest extends TestCase
{
    #[Test]
    public function reachableWhenWriteServiceAnswersPing(): void
    {
        $reachability = new ConnectionManagerSolrReachability($this->connectionManagerPinging(true), new NullLogger());

        self::assertTrue($reachability->isReachable(1));
    }

    #[Test]
    public function notReachableWhenPingFails(): void
    {
        $reachability = new ConnectionManagerSolrReachability($this->connectionManagerPinging(false), new NullLogger());

        self::assertFalse($reachability->isReachable(1));
    }

    #[Test]
    public function notReachableWhenConnectionCannotBeResolved(): void
    {
        $connectionManager = $this->createMock(ConnectionManager::class);
        $connectionManager->method('getConnectionByRootPageId')
            ->willThrowException(new RuntimeException('no connection'));

        $reachability = new ConnectionManagerSolrReachability($connectionManager, new NullLogger());

        self::assertFalse($reachability->isReachable(1));
    }

    #[Test]
    public function logsAWarningNamingTheRootAndTheExceptionWhenTheConnectionCannotBeResolved(): void
    {
        $connectionManager = $this->createMock(ConnectionManager::class);
        $connectionManager->method('getConnectionByRootPageId')
            ->willThrowException(new RuntimeException('no connection'));
        $logger = new RecordingLogger();

        (new ConnectionManagerSolrReachability($connectionManager, $logger))->isReachable(7);

        self::assertSame([LogLevel::WARNING], $logger->levels);
        self::assertSame(7, $logger->contexts[0]['root']);
        self::assertSame(RuntimeException::class, $logger->contexts[0]['exception_class']);
        self::assertSame('no connection', $logger->contexts[0]['exception_message']);
    }

    #[Test]
    public function logsAWarningNamingTheRootWhenSolrDoesNotAnswerThePing(): void
    {
        $logger = new RecordingLogger();

        (new ConnectionManagerSolrReachability($this->connectionManagerPinging(false), $logger))->isReachable(7);

        self::assertSame([LogLevel::WARNING], $logger->levels);
        self::assertSame(7, $logger->contexts[0]['root']);
    }

    #[Test]
    public function pingsOnlyOncePerRootWithinTheRequest(): void
    {
        $writeService = $this->createMock(SolrWriteService::class);
        $writeService->expects(self::once())->method('ping')->willReturn(true);

        $connection = $this->createMock(SolrConnection::class);
        $connection->method('getWriteService')->willReturn($writeService);

        $connectionManager = $this->createMock(ConnectionManager::class);
        $connectionManager->method('getConnectionByRootPageId')->willReturn($connection);

        $reachability = new ConnectionManagerSolrReachability($connectionManager, new NullLogger());

        self::assertTrue($reachability->isReachable(1));
        self::assertTrue($reachability->isReachable(1), 'The ping result is cached and reused within the request');
    }

    private function connectionManagerPinging(bool $answers): ConnectionManager
    {
        $writeService = $this->createMock(SolrWriteService::class);
        $writeService->method('ping')->willReturn($answers);

        $connection = $this->createMock(SolrConnection::class);
        $connection->method('getWriteService')->willReturn($writeService);

        $connectionManager = $this->createMock(ConnectionManager::class);
        $connectionManager->method('getConnectionByRootPageId')->willReturn($connection);

        return $connectionManager;
    }
}
