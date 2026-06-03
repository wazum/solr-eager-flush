<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\EventListener;

use ApacheSolrForTypo3\Solr\Domain\Index\Queue\UpdateHandler\EventListener\Events\ProcessingFinishedEvent;
use Psr\Log\LoggerInterface;
use Throwable;
use Wazum\SolrEagerFlush\Configuration\ExtensionConfiguration;
use Wazum\SolrEagerFlush\Drainer\IndexQueueDrainer;
use Wazum\SolrEagerFlush\Gate\EagerFlushGate;

final readonly class EagerFlushListener
{
    /**
     * @param iterable<EagerFlushGate> $gates
     */
    public function __construct(
        private iterable $gates,
        private IndexQueueDrainer $drainer,
        private ExtensionConfiguration $config,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(ProcessingFinishedEvent $event): void
    {
        try {
            foreach ($this->gates as $gate) {
                if (!$gate->shouldProceed()) {
                    $this->logger->debug('eager-flush skipped', ['gate' => $gate::class]);

                    return;
                }
            }

            $start = microtime(true);
            $result = $this->drainer->drain($this->config->deltaMax);
            $context = [
                'duration_ms' => (int) round((microtime(true) - $start) * 1000.0),
                'succeeded' => $result->succeededRoots,
                'failed' => $result->failedRoots,
            ];

            $result->hasFailures()
                ? $this->logger->warning('eager-flush completed with failures', $context)
                : $this->logger->info('eager-flush completed', $context);
        } catch (Throwable $e) {
            $this->logger->error('eager-flush failed', ['exception' => $e]);
        }
    }
}
