<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Tests\Functional\Gate;

use ApacheSolrForTypo3\Solr\Task\IndexQueueWorkerTask;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Task\TaskSerializer;
use Wazum\SolrEagerFlush\Gate\SchedulerActivityGate;
use Wazum\SolrEagerFlush\Tests\Functional\AbstractFunctionalTestCase;

final class SchedulerActivityGateTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function proceedsWhenNoSolrTaskIsRunning(): void
    {
        $gate = $this->resolveGate();
        self::assertTrue($gate->shouldProceed());
    }

    #[Test]
    public function skipsWhenIndexQueueWorkerTaskIsRunning(): void
    {
        $this->insertSchedulerTask(
            taskObject: GeneralUtility::makeInstance(IndexQueueWorkerTask::class),
            serializedExecutions: serialize([99]),
        );

        self::assertFalse($this->resolveGate()->shouldProceed());
    }

    #[Test]
    public function ignoresNonSolrTasksWithRunningExecutions(): void
    {
        $this->insertSchedulerTask(
            taskObject: new stdClass(),
            serializedExecutions: serialize([99]),
        );

        self::assertTrue($this->resolveGate()->shouldProceed());
    }

    #[Test]
    public function ignoresSolrTaskWithEmptyExecutions(): void
    {
        $this->insertSchedulerTask(
            taskObject: GeneralUtility::makeInstance(IndexQueueWorkerTask::class),
            serializedExecutions: '',
        );

        self::assertTrue($this->resolveGate()->shouldProceed());
    }

    #[Test]
    public function ignoresDisabledSolrTask(): void
    {
        $this->insertSchedulerTask(
            taskObject: GeneralUtility::makeInstance(IndexQueueWorkerTask::class),
            serializedExecutions: serialize([99]),
            disable: 1,
        );

        self::assertTrue($this->resolveGate()->shouldProceed());
    }

    private function insertSchedulerTask(
        object $taskObject,
        string $serializedExecutions,
        int $disable = 0,
        int $deleted = 0,
    ): void {
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_scheduler_task')
            ->insert('tx_scheduler_task', [
                'disable' => $disable,
                'deleted' => $deleted,
                'serialized_task_object' => serialize($taskObject),
                'serialized_executions' => $serializedExecutions,
            ]);
    }

    private function resolveGate(): SchedulerActivityGate
    {
        return new SchedulerActivityGate(
            GeneralUtility::makeInstance(ConnectionPool::class),
            GeneralUtility::makeInstance(TaskSerializer::class),
        );
    }
}
