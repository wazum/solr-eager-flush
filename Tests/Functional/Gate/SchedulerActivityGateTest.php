<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Tests\Functional\Gate;

use ApacheSolrForTypo3\Solr\Task\IndexQueueWorkerTask;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
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
            taskClassName: IndexQueueWorkerTask::class,
            serializedExecutions: serialize([time()]),
        );

        self::assertFalse($this->resolveGate()->shouldProceed());
    }

    #[Test]
    public function ignoresNonSolrTasksWithRunningExecutions(): void
    {
        $this->insertSchedulerTask(
            taskClassName: stdClass::class,
            serializedExecutions: serialize([time()]),
        );

        self::assertTrue($this->resolveGate()->shouldProceed());
    }

    #[Test]
    public function ignoresSolrTaskWithEmptyExecutions(): void
    {
        $this->insertSchedulerTask(
            taskClassName: IndexQueueWorkerTask::class,
            serializedExecutions: '',
        );

        self::assertTrue($this->resolveGate()->shouldProceed());
    }

    #[Test]
    public function skipsWhenADisabledWorkerTaskIsStillExecuting(): void
    {
        $this->insertSchedulerTask(
            taskClassName: IndexQueueWorkerTask::class,
            serializedExecutions: serialize([time()]),
            disable: 1,
        );

        self::assertFalse(
            $this->resolveGate()->shouldProceed(),
            'Disabling a task does not stop the run it already started',
        );
    }

    #[Test]
    public function proceedsWhenTheWorkerExecutionOutlivedTheSchedulerMaxLifetime(): void
    {
        $this->insertSchedulerTask(
            taskClassName: IndexQueueWorkerTask::class,
            serializedExecutions: serialize([time() - 25 * 3600]),
        );

        self::assertTrue(
            $this->resolveGate()->shouldProceed(),
            'A crashed run must stop blocking the flush once the scheduler would drop its execution',
        );
    }

    #[Test]
    public function skipsWhenAStaleAndARunningExecutionAreRecordedTogether(): void
    {
        $this->insertSchedulerTask(
            taskClassName: IndexQueueWorkerTask::class,
            serializedExecutions: serialize([time() - 25 * 3600, time()]),
        );

        self::assertFalse($this->resolveGate()->shouldProceed());
    }

    #[Test]
    public function skipsWhenTheTaskTypeColumnNamesTheWorker(): void
    {
        $this->skipWithoutTaskTypeColumn();

        $this->insertSchedulerTask(
            taskClassName: null,
            serializedExecutions: serialize([time()]),
            taskType: IndexQueueWorkerTask::class,
        );

        self::assertFalse(
            $this->resolveGate()->shouldProceed(),
            'TYPO3 14 records the task class in tasktype and leaves serialized_task_object empty',
        );
    }

    private function insertSchedulerTask(
        ?string $taskClassName,
        string $serializedExecutions,
        int $disable = 0,
        int $deleted = 0,
        ?string $taskType = null,
    ): void {
        $row = [
            'disable' => $disable,
            'deleted' => $deleted,
            'serialized_task_object' => null === $taskClassName
                ? ''
                : \sprintf('O:%d:"%s":0:{}', \strlen($taskClassName), $taskClassName),
            'serialized_executions' => $serializedExecutions,
        ];
        if (null !== $taskType) {
            $row['tasktype'] = $taskType;
        }

        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_scheduler_task')
            ->insert('tx_scheduler_task', $row);
    }

    private function skipWithoutTaskTypeColumn(): void
    {
        $hasTaskTypeColumn = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_scheduler_task')
            ->createSchemaManager()
            ->introspectTable('tx_scheduler_task')
            ->hasColumn('tasktype');

        if (!$hasTaskTypeColumn) {
            self::markTestSkipped('tx_scheduler_task has no tasktype column before TYPO3 14');
        }
    }

    private function resolveGate(): SchedulerActivityGate
    {
        return $this->get(SchedulerActivityGate::class);
    }
}
