<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Tests\Unit\Site;

use ApacheSolrForTypo3\Solr\ConnectionManager;
use ApacheSolrForTypo3\Solr\System\Solr\Service\SolrWriteService;
use ApacheSolrForTypo3\Solr\System\Solr\SolrConnection;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Wazum\SolrEagerFlush\Site\ConnectionManagerSolrReachability;

final class ConnectionManagerSolrReachabilityTest extends TestCase
{
    public function testReachableWhenWriteServiceAnswersPing(): void
    {
        $reachability = new ConnectionManagerSolrReachability($this->connectionManagerPinging(true));

        self::assertTrue($reachability->isReachable(1));
    }

    public function testNotReachableWhenPingFails(): void
    {
        $reachability = new ConnectionManagerSolrReachability($this->connectionManagerPinging(false));

        self::assertFalse($reachability->isReachable(1));
    }

    public function testNotReachableWhenConnectionCannotBeResolved(): void
    {
        $connectionManager = $this->createMock(ConnectionManager::class);
        $connectionManager->method('getConnectionByRootPageId')
            ->willThrowException(new RuntimeException('no connection'));

        $reachability = new ConnectionManagerSolrReachability($connectionManager);

        self::assertFalse($reachability->isReachable(1));
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
