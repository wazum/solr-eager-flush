<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Site;

interface SiteEagerFlushPolicy
{
    /**
     * Whether eager flush is enabled for the site identified by $rootPageId.
     */
    public function isEnabledForRoot(int $rootPageId): bool;
}
