<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\RunContext;

use TYPO3\CMS\Core\SingletonInterface;

final class EagerFlushRunContext implements SingletonInterface
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
