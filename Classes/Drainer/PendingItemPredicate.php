<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Drainer;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use Wazum\SolrEagerFlush\TypeFilter\TypeFilterMode;

final readonly class PendingItemPredicate
{
    /**
     * @return list<string>
     */
    public function whereClauses(
        QueryBuilder $queryBuilder,
        int $now,
        ?TypeFilterMode $mode = null,
        ?int $rootPageId = null,
    ): array {
        $expr = $queryBuilder->expr();

        $clauses = [
            $expr->gt('changed', 'indexed'),
            $expr->lte('changed', $queryBuilder->createNamedParameter($now, Connection::PARAM_INT)),
            $expr->eq('errors', $queryBuilder->createNamedParameter('')),
        ];

        $typeClause = match ($mode) {
            TypeFilterMode::Pages => $expr->eq('item_type', $queryBuilder->createNamedParameter('pages')),
            TypeFilterMode::Records => $expr->neq('item_type', $queryBuilder->createNamedParameter('pages')),
            default => null,
        };
        if (null !== $typeClause) {
            $clauses[] = $typeClause;
        }

        if (null !== $rootPageId) {
            $clauses[] = $expr->eq('root', $queryBuilder->createNamedParameter($rootPageId, Connection::PARAM_INT));
        }

        return $clauses;
    }
}
