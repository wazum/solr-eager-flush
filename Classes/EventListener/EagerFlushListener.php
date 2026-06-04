<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\EventListener;

use ApacheSolrForTypo3\Solr\Domain\Index\Queue\UpdateHandler\EventListener\Events\ProcessingFinishedEvent;
use Psr\Log\LoggerInterface;
use Throwable;
use Wazum\SolrEagerFlush\Configuration\ExtensionConfiguration;
use Wazum\SolrEagerFlush\Drainer\IndexQueueDrainer;
use Wazum\SolrEagerFlush\Drainer\IndexQueuePressure;
use Wazum\SolrEagerFlush\Gate\EagerFlushGate;
use Wazum\SolrEagerFlush\Site\SiteRootResolver;

final readonly class EagerFlushListener
{
    /**
     * @param iterable<EagerFlushGate> $gates
     */
    public function __construct(
        private iterable $gates,
        private IndexQueueDrainer $drainer,
        private SiteRootResolver $rootResolver,
        private IndexQueuePressure $pressure,
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

            $rootPageId = $this->rootResolver->resolveRootPageId($event->getDataUpdateEvent());
            if (null === $rootPageId) {
                $this->logger->debug('eager-flush skipped', ['reason' => 'site-unresolved']);

                return;
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
