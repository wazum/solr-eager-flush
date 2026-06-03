<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Drainer;

use ApacheSolrForTypo3\Solr\Domain\Index\IndexService;
use ApacheSolrForTypo3\Solr\Domain\Site\SiteRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final readonly class IndexServiceSiteIndexer implements SiteIndexer
{
    public function __construct(
        private SiteRepository $siteRepository,
    ) {}

    public function index(int $rootPageId, int $limit): bool
    {
        $site = $this->siteRepository->getSiteByRootPageId($rootPageId);

        return GeneralUtility::makeInstance(IndexService::class, $site)->indexItems($limit);
    }
}
