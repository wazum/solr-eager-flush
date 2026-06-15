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
        private readonly ExtensionConfiguration $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function run(int $rootPageId): void
    {
        try {
            foreach ($this->gates as $gate) {
                if (!$gate->shouldProceed()) {
                    $this->logger->debug('eager-flush skipped', ['gate' => $gate::class]);

                    return;
                }
            }

            if (!$this->pressure->isUnderLimit($rootPageId)) {
                $this->logger->debug('eager-flush skipped', ['reason' => 'queue-pressure', 'root' => $rootPageId]);

                return;
            }

            $start = microtime(true);
            $result = $this->drainer->drain($this->config->deltaMax, $rootPageId);
            $context = [
                'duration_ms' => (int) round((microtime(true) - $start) * 1000.0),
                'succeeded' => $result->succeededRoots,
                'failed' => $result->failedRoots,
            ];

            if (!$result->hasFailures()) {
                $this->logger->info('eager-flush completed', $context);

                return;
            }

            $this->logger->warning('eager-flush completed with failures', $context + ['reasons' => $result->failureReasons]);
        } catch (Throwable $e) {
            $this->logger->error('eager-flush failed', ['exception' => $e]);
        }
    }
}
