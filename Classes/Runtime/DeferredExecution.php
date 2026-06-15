<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Runtime;

interface DeferredExecution
{
    /**
     * Whether a deferred task is guaranteed to run at the end of the current request.
     */
    public function isSupported(): bool;

    public function defer(callable $task): void;
}
