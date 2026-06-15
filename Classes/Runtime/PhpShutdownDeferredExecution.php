<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Runtime;

final readonly class PhpShutdownDeferredExecution implements DeferredExecution
{
    public function isSupported(): bool
    {
        return !\in_array(\PHP_SAPI, ['cli', 'phpdbg', 'embed'], true);
    }

    public function defer(callable $task): void
    {
        register_shutdown_function($task);
    }
}
