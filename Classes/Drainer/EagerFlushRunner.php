<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Drainer;

use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Locking\Exception\LockAcquireWouldBlockException;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;
use Wazum\SolrEagerFlush\Configuration\ExtensionConfiguration;
use Wazum\SolrEagerFlush\Gate\EagerFlushGate;

class EagerFlushRunner
{
    /**
     * @param iterable<EagerFlushGate> $gates
     */
    public function __construct(
        private readonly iterable $gates,
        private readonly IndexQueueDrainer $drainer,
        private readonly IndexQueuePressure $pressure,
        private readonly ExtensionConfiguration $configuration,
        private readonly LoggerInterface $logger,
        private readonly LockFactory $lockFactory,
    ) {}

    public function run(int $rootPageId): void
    {
        try {
            foreach ($this->gates as $gate) {
                if (!$gate->shouldProceed()) {
                    $this->logger->info('eager-flush skipped', [
                        'reason' => 'gate',
                        'gate' => $gate::class,
                        'root' => $rootPageId,
                    ]);

                    return;
                }
            }

            if (!$this->pressure->isUnderLimit($rootPageId)) {
                $this->logger->info('eager-flush skipped', [
                    'reason' => 'queue-pressure',
                    'root' => $rootPageId,
                    'limit' => $this->configuration->indexQueueLimit,
                ]);

                return;
            }

            $locker = $this->lockFactory->createLocker(
                'solr-eager-flush-' . $rootPageId,
                LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK,
            );
            if (!$this->acquireWithoutWaiting($locker)) {
                // Backing off beats waiting: that flush indexes this site's queue anyway.
                $this->logger->info('eager-flush skipped', ['reason' => 'already-flushing', 'root' => $rootPageId]);

                return;
            }

            try {
                $this->drainAndReport($rootPageId);
            } finally {
                $locker->release();
            }
        } catch (Throwable $e) {
            $this->logger->error('eager-flush failed', [
                'root' => $rootPageId,
                'exception' => $e,
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
                'exception_code' => $e->getCode(),
                'location' => $e->getFile() . ':' . $e->getLine(),
            ]);
        }
    }

    private function acquireWithoutWaiting(LockingStrategyInterface $locker): bool
    {
        try {
            return $locker->acquire(
                LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK,
            );
        } catch (LockAcquireWouldBlockException) {
            return false;
        }
    }

    private function drainAndReport(int $rootPageId): void
    {
        $start = microtime(true);
        $result = $this->drainer->drain($this->configuration->deltaMax, $rootPageId);
        $context = [
            'root' => $rootPageId,
            'duration_ms' => (int) round((microtime(true) - $start) * 1000.0),
            'succeeded' => $result->succeededRoots,
            'failed' => $result->failedRoots,
        ];

        if ($result->hasFailures()) {
            $this->logger->warning('eager-flush completed with failures', $context + [
                'reasons' => $result->failureReasons,
                'skipped' => $result->skippedRoots,
            ]);

            return;
        }

        // Whoever reads the log must tell an empty queue from a site that was skipped.
        if ([] === $result->succeededRoots) {
            $this->logger->info('eager-flush skipped', $context + [
                'reason' => [] === $result->skippedRoots ? 'no-pending-items' : 'sites-skipped',
                'skipped' => $result->skippedRoots,
            ]);

            return;
        }

        $this->logger->info('eager-flush completed', $context);
    }
}
