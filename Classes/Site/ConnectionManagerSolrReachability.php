<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Site;

use ApacheSolrForTypo3\Solr\ConnectionManager;
use Psr\Log\LoggerInterface;
use Throwable;

final class ConnectionManagerSolrReachability implements SolrReachability
{
    /** @var array<int, bool> */
    private array $reachableByRoot = [];

    public function __construct(
        private readonly ConnectionManager $connectionManager,
        private readonly LoggerInterface $logger,
    ) {}

    public function isReachable(int $rootPageId): bool
    {
        return $this->reachableByRoot[$rootPageId] ??= $this->ping($rootPageId);
    }

    private function ping(int $rootPageId): bool
    {
        try {
            if ($this->connectionManager->getConnectionByRootPageId($rootPageId)->getWriteService()->ping()) {
                return true;
            }

            $this->logger->warning('eager-flush: Solr did not answer the ping, skipping site', ['root' => $rootPageId]);
        } catch (Throwable $e) {
            $this->logger->warning('eager-flush: Solr connection unavailable, skipping site', [
                'root' => $rootPageId,
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
            ]);
        }

        return false;
    }
}
