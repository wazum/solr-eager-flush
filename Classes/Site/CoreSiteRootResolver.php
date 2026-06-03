<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Site;

use ApacheSolrForTypo3\Solr\Domain\Index\Queue\UpdateHandler\Events\DataUpdateEventInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;

final readonly class CoreSiteRootResolver implements SiteRootResolver
{
    public function __construct(
        private SiteFinder $siteFinder,
        private ConnectionPool $connectionPool,
        private LoggerInterface $logger,
    ) {}

    public function resolveRootPageId(DataUpdateEventInterface $event): ?int
    {
        try {
            $pageId = $event->isPageUpdate()
                ? $event->getUid()
                : $this->pageIdOf($event->getTable(), $event->getUid());
            if (null === $pageId) {
                return null;
            }

            return $this->siteFinder->getSiteByPageId($pageId)->getRootPageId();
        } catch (SiteNotFoundException) {
            return null;
        } catch (Throwable $e) {
            $this->logger->warning('eager-flush: failed to resolve the affected site root', ['exception' => $e]);

            return null;
        }
    }

    private function pageIdOf(string $table, int $uid): ?int
    {
        $queryBuilder = $this->connectionPool->getConnectionForTable($table)->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();

        $pid = $queryBuilder
            ->select('pid')
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();

        return false === $pid ? null : (int) $pid;
    }
}
