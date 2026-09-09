<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Drainer;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Wazum\SolrEagerFlush\Runtime\DeferredExecution;
use Wazum\SolrEagerFlush\Runtime\ResponseDetacher;

#[Autoconfigure(shared: true)]
class EagerFlushScheduler
{
    /** @var array<int, true> */
    private array $rootPageIds = [];

    private bool $flushDeferred = false;

    public function __construct(
        private readonly DeferredExecution $deferredExecution,
        private readonly ResponseDetacher $responseDetacher,
        private readonly EagerFlushRunner $runner,
        private readonly LoggerInterface $logger,
    ) {}

    public function schedule(int $rootPageId): void
    {
        // Inline indexing would read records before DataHandler writes their relations, and run once per saved record.
        if (!$this->deferredExecution->isSupported()) {
            $this->logger->debug('eager-flush skipped', ['reason' => 'deferral-unsupported', 'root' => $rootPageId]);

            return;
        }

        $this->rootPageIds[$rootPageId] = true;
        if (!$this->flushDeferred) {
            $this->flushDeferred = true;
            $this->deferredExecution->defer($this->flush(...));
        }
    }

    public function flush(): void
    {
        $rootPageIds = array_keys($this->rootPageIds);
        $this->rootPageIds = [];
        $this->flushDeferred = false;
        if ([] === $rootPageIds) {
            return;
        }

        if (!$this->responseDetacher->detach()) {
            // Otherwise the only symptom is a slow save, with nothing pointing at the flush.
            $this->logger->notice('eager-flush: response not detached, the client waits for the flush', [
                'sapi' => \PHP_SAPI,
                'roots' => $rootPageIds,
            ]);
        }

        foreach ($rootPageIds as $rootPageId) {
            $this->runner->run($rootPageId);
        }
    }
}
