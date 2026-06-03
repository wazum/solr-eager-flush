<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\RunContext;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

#[Autoconfigure(shared: true)]
final class EagerFlushRunContext
{
    private int $depth = 0;

    public function enter(): void
    {
        ++$this->depth;
    }

    public function leave(): void
    {
        if ($this->depth > 0) {
            --$this->depth;
        }
    }

    public function isActive(): bool
    {
        return $this->depth > 0;
    }
}
