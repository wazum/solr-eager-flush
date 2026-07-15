<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Site;

use ApacheSolrForTypo3\Solr\ConnectionManager;
use Throwable;

final class ConnectionManagerSolrReachability implements SolrReachability
{
    /** @var array<int, bool> */
    private array $reachableByRoot = [];

    public function __construct(
        private readonly ConnectionManager $connectionManager,
    ) {}

    public function isReachable(int $rootPageId): bool
    {
        return $this->reachableByRoot[$rootPageId] ??= $this->ping($rootPageId);
    }

    private function ping(int $rootPageId): bool
    {
        try {
            return $this->connectionManager
                ->getConnectionByRootPageId($rootPageId)
                ->getWriteService()
                ->ping();
        } catch (Throwable) {
            return false;
        }
    }
}
