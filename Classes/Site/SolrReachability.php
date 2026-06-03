<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Site;

interface SolrReachability
{
    /**
     * Whether the Solr connection of the site identified by $rootPageId currently answers.
     */
    public function isReachable(int $rootPageId): bool;
}
