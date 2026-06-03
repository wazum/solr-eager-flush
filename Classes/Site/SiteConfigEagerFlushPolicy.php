<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Site;

use Throwable;
use TYPO3\CMS\Core\Site\SiteFinder;

final readonly class SiteConfigEagerFlushPolicy implements SiteEagerFlushPolicy
{
    private const SITE_SETTING = 'solr_eager_flush_enabled';

    public function __construct(
        private SiteFinder $siteFinder,
    ) {}

    public function isEnabledForRoot(int $rootPageId): bool
    {
        try {
            $configuration = $this->siteFinder->getSiteByRootPageId($rootPageId)->getConfiguration();
        } catch (Throwable) {
            return true;
        }

        return (bool) ($configuration[self::SITE_SETTING] ?? true);
    }
}
