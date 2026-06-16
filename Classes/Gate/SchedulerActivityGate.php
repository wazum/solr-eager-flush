<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Gate;

use ApacheSolrForTypo3\Solr\Task\IndexQueueWorkerTask;
use Throwable;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Scheduler\Task\TaskSerializer;

final readonly class SchedulerActivityGate implements EagerFlushGate
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private TaskSerializer $taskSerializer,
    ) {}

    public function shouldProceed(): bool
    {
        $queryBuilder = $this->connectionPool
            ->getConnectionForTable('tx_scheduler_task')
            ->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();

        try {
            $rows = $queryBuilder
                ->select('serialized_task_object', 'serialized_executions')
                ->from('tx_scheduler_task')
                ->where(
                    $queryBuilder->expr()->eq('deleted', 0),
                    $queryBuilder->expr()->eq('disable', 0),
                    $queryBuilder->expr()->isNotNull('serialized_executions'),
                )
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (Throwable) {
            return false;
        }

        foreach ($rows as $row) {
            if ('' === (string) $row['serialized_executions']) {
                continue;
            }
            $className = $this->taskSerializer->extractClassName(
                (string) $row['serialized_task_object'],
            );
            if ($className === IndexQueueWorkerTask::class) {
                return false;
            }
        }

        return true;
    }
}
