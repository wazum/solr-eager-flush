<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Site;

use ApacheSolrForTypo3\Solr\Domain\Index\Queue\RecordMonitor\Exception\RootPageRecordNotFoundException;
use ApacheSolrForTypo3\Solr\Domain\Index\Queue\RecordMonitor\Helper\RootPageResolver;
use ApacheSolrForTypo3\Solr\Domain\Index\Queue\UpdateHandler\Events\DataUpdateEventInterface;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class CoreSiteRootResolver implements SiteRootResolver
{
    public function __construct(
        private RootPageResolver $rootPageResolver,
        private LoggerInterface $logger,
    ) {}

    public function resolveRootPageIds(DataUpdateEventInterface $event): array
    {
        try {
            $roots = $this->rootPageResolver->getResponsibleRootPageIds($event->getTable(), $event->getUid());
        } catch (RootPageRecordNotFoundException) {
            return [];
        } catch (Throwable $e) {
            $this->logger->warning('eager-flush: failed to resolve the affected site roots', ['exception' => $e]);

            return [];
        }

        return array_values(array_unique($roots));
    }
}
