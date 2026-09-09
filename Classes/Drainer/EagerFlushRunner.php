<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Drainer;

use Psr\Log\LoggerInterface;
use Throwable;
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
}
