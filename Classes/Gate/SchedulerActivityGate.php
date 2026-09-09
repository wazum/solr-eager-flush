<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Gate;

use ApacheSolrForTypo3\Solr\Task\IndexQueueWorkerTask;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as CoreExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Scheduler\Task\TaskSerializer;

final readonly class SchedulerActivityGate implements EagerFlushGate
{
    private const DEFAULT_MAX_LIFETIME_IN_MINUTES = 1440;

    public function __construct(
        private ConnectionPool $connectionPool,
        private TaskSerializer $taskSerializer,
        private CoreExtensionConfiguration $extensionConfiguration,
    ) {}

    public function shouldProceed(): bool
    {
        $queryBuilder = $this->connectionPool
            ->getConnectionForTable('tx_scheduler_task')
            ->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();

        try {
            $rows = $queryBuilder
                ->select('*')
                ->from('tx_scheduler_task')
                // No `disable` filter: disabling a task leaves the run it started alive, and the scheduler's own check ignores the flag too.
                ->where(
                    $queryBuilder->expr()->eq('deleted', 0),
                    $queryBuilder->expr()->isNotNull('serialized_executions'),
                )
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (Throwable) {
            return false;
        }

        foreach ($rows as $row) {
            if ($this->isIndexQueueWorkerTask($row) && $this->isExecuting((string) $row['serialized_executions'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isIndexQueueWorkerTask(array $row): bool
    {
        // TYPO3 14 keeps the class in its own column and leaves serialized_task_object empty.
        if (IndexQueueWorkerTask::class === ($row['tasktype'] ?? null)) {
            return true;
        }

        return IndexQueueWorkerTask::class === $this->taskSerializer->extractClassName(
            (string) ($row['serialized_task_object'] ?? ''),
        );
    }

    private function isExecuting(string $serializedExecutions): bool
    {
        if ('' === $serializedExecutions) {
            return false;
        }

        $executions = unserialize($serializedExecutions, ['allowed_classes' => false]);
        if (!\is_array($executions)) {
            return false;
        }

        // A killed run leaves its execution behind, which the scheduler drops only after maxLifetime.
        $startedAfter = time() - $this->maxExecutionAgeInSeconds();

        foreach ($executions as $startedAt) {
            if ((int) $startedAt > $startedAfter) {
                return true;
            }
        }

        return false;
    }

    private function maxExecutionAgeInSeconds(): int
    {
        try {
            $configuration = $this->extensionConfiguration->get('scheduler');
        } catch (Throwable) {
            $configuration = [];
        }

        $maxLifetime = (int) (\is_array($configuration) ? ($configuration['maxLifetime'] ?? 0) : 0);

        return ($maxLifetime > 0 ? $maxLifetime : self::DEFAULT_MAX_LIFETIME_IN_MINUTES) * 60;
    }
}
