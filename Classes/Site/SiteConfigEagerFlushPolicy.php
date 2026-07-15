<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Site;

use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;

final readonly class SiteConfigEagerFlushPolicy implements SiteEagerFlushPolicy
{
    private const SITE_SETTING = 'solr_eager_flush_enabled';

    public function __construct(
        private SiteFinder $siteFinder,
        private LoggerInterface $logger,
    ) {}

    public function isEnabledForRoot(int $rootPageId): bool
    {
        try {
            $configuration = $this->siteFinder->getSiteByRootPageId($rootPageId)->getConfiguration();
        } catch (SiteNotFoundException) {
            return false;
        } catch (Throwable $e) {
            $this->logger->warning('eager-flush: failed to read site configuration', ['exception' => $e]);

            return false;
        }

        return filter_var($configuration[self::SITE_SETTING] ?? true, \FILTER_VALIDATE_BOOLEAN);
    }
}
