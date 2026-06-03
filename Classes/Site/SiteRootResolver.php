<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Site;

use ApacheSolrForTypo3\Solr\Domain\Index\Queue\UpdateHandler\Events\DataUpdateEventInterface;

interface SiteRootResolver
{
    /**
     * Resolves the site root page id affected by a data update, or null when it cannot be determined.
     */
    public function resolveRootPageId(DataUpdateEventInterface $event): ?int;
}
