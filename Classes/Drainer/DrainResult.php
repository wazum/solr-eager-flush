<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Drainer;

final readonly class DrainResult
{
    /**
     * @param list<int> $succeededRoots
     * @param list<int> $failedRoots
     */
    public function __construct(
        public array $succeededRoots = [],
        public array $failedRoots = [],
    ) {}

    public function hasFailures(): bool
    {
        return [] !== $this->failedRoots;
    }
}
