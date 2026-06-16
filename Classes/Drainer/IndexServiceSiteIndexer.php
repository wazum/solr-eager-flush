<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Drainer;

use ApacheSolrForTypo3\Solr\Domain\Index\IndexService;
use ApacheSolrForTypo3\Solr\Domain\Site\SiteRepository;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Wazum\SolrEagerFlush\Configuration\ExtensionConfiguration;

final readonly class IndexServiceSiteIndexer implements SiteIndexer
{
    public function __construct(
        private SiteRepository $siteRepository,
        private ExtensionConfiguration $configuration,
        private ConnectionPool $connectionPool,
        private PendingItemPredicate $predicate,
    ) {}

    public function index(int $rootPageId, int $limit): bool
    {
        $site = $this->siteRepository->getSiteByRootPageId($rootPageId);
        $queue = GeneralUtility::makeInstance(
            TypeFilteringQueue::class,
            $this->connectionPool,
            $this->predicate,
            $this->configuration->typeFilter,
        );

        return GeneralUtility::makeInstance(IndexService::class, $site, $queue)->indexItems($limit);
    }
}
