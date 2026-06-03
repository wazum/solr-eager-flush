<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Site;

use ApacheSolrForTypo3\Solr\ConnectionManager;
use Throwable;

final readonly class ConnectionManagerSolrReachability implements SolrReachability
{
    public function __construct(
        private ConnectionManager $connectionManager,
    ) {}

    public function isReachable(int $rootPageId): bool
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
