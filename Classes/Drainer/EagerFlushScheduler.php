<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Drainer;

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
    ) {}

    public function schedule(int $rootPageId): void
    {
        if (!$this->deferredExecution->isSupported()) {
            $this->runner->run($rootPageId);

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
        if ([] === $rootPageIds) {
            return;
        }

        $this->responseDetacher->detach();

        foreach ($rootPageIds as $rootPageId) {
            $this->runner->run($rootPageId);
        }
    }
}
