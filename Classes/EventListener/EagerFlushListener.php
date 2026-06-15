<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\EventListener;

use ApacheSolrForTypo3\Solr\Domain\Index\Queue\UpdateHandler\EventListener\Events\ProcessingFinishedEvent;
use Psr\Log\LoggerInterface;
use Throwable;
use Wazum\SolrEagerFlush\Drainer\EagerFlushScheduler;
use Wazum\SolrEagerFlush\Site\SiteRootResolver;

final readonly class EagerFlushListener
{
    public function __construct(
        private SiteRootResolver $rootResolver,
        private EagerFlushScheduler $scheduler,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(ProcessingFinishedEvent $event): void
    {
        try {
            $rootPageId = $this->rootResolver->resolveRootPageId($event->getDataUpdateEvent());
            if (null === $rootPageId) {
                $this->logger->debug('eager-flush skipped', ['reason' => 'site-unresolved']);

                return;
            }

            $this->scheduler->schedule($rootPageId);
        } catch (Throwable $e) {
            $this->logger->error('eager-flush failed', ['exception' => $e]);
        }
    }
}
