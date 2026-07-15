<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Site;

use ApacheSolrForTypo3\Solr\Domain\Index\Queue\UpdateHandler\Events\DataUpdateEventInterface;

interface SiteRootResolver
{
    /**
     * Resolves every site root page id responsible for a data update.
     *
     * A record can belong to several roots (for example through ext:solr's additionalPageIds),
     * so all of them must be flushed, not just the one the save originated from.
     *
     * @return list<int> empty when none can be determined
     */
    public function resolveRootPageIds(DataUpdateEventInterface $event): array;
}
