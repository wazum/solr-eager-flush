<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Drainer;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

final readonly class PendingItemPredicate
{
    /**
     * @return list<string>
     */
    public function whereClauses(QueryBuilder $queryBuilder, int $now): array
    {
        $expr = $queryBuilder->expr();

        return [
            $expr->gt('changed', 'indexed'),
            $expr->lte('changed', $queryBuilder->createNamedParameter($now, Connection::PARAM_INT)),
            $expr->eq('errors', $queryBuilder->createNamedParameter('')),
        ];
    }
}
