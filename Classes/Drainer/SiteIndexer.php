<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Drainer;

interface SiteIndexer
{
    /**
     * Indexes up to $limit pending index-queue items of the site identified by $rootPageId.
     *
     * @return bool whether indexing (and the Solr commit) succeeded
     */
    public function index(int $rootPageId, int $limit): bool;
}
