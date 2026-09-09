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
        } catch (SiteNotFoundException $e) {
            // ext:solr queues items per site, so a queued root without one is a broken
            // configuration rather than a site that opted out.
            $this->logger->warning('eager-flush: no site for the queued root, skipping it', [
                'root' => $rootPageId,
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
            ]);

            return false;
        } catch (Throwable $e) {
            $this->logger->warning('eager-flush: failed to read site configuration', [
                'root' => $rootPageId,
                'exception' => $e,
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
            ]);

            return false;
        }

        return filter_var($configuration[self::SITE_SETTING] ?? true, \FILTER_VALIDATE_BOOLEAN);
    }
}
