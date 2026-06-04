<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Tests\Unit\Drainer;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use Wazum\SolrEagerFlush\Drainer\PendingItemPredicate;

final class PendingItemPredicateTest extends TestCase
{
    #[Test]
    public function returnsThreeWhereClauseFragments(): void
    {
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $expr = $this->createMock(ExpressionBuilder::class);
        $queryBuilder->method('expr')->willReturn($expr);
        $queryBuilder->method('createNamedParameter')->willReturnCallback(
            static fn (mixed $value): string => \is_int($value) ? ':now' : ':emptyString',
        );
        $expr->method('gt')->with('changed', 'indexed')->willReturn('changed > indexed');
        $expr->method('lte')->with('changed', ':now')->willReturn('changed <= :now');
        $expr->method('eq')->with('errors', ':emptyString')->willReturn('errors = :emptyString');

        $clauses = (new PendingItemPredicate())->whereClauses($queryBuilder, 1_700_000_000);

        self::assertSame(
            ['changed > indexed', 'changed <= :now', 'errors = :emptyString'],
            $clauses,
        );
    }
}
