<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Drainer;

final readonly class DrainResult
{
    /**
     * @param list<int> $succeededRoots
     * @param list<int> $failedRoots
     * @param array<int, string> $failureReasons root page id => failure reason
     */
    public function __construct(
        public array $succeededRoots = [],
        public array $failedRoots = [],
        public array $failureReasons = [],
    ) {}

    public function hasFailures(): bool
    {
        return [] !== $this->failedRoots;
    }
}
