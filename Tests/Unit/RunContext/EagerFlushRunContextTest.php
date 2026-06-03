<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Tests\Unit\RunContext;

use PHPUnit\Framework\TestCase;
use Wazum\SolrEagerFlush\RunContext\EagerFlushRunContext;

final class EagerFlushRunContextTest extends TestCase
{
    public function testStartsInactive(): void
    {
        self::assertFalse((new EagerFlushRunContext())->isActive());
    }

    public function testEnterSetsActiveTrue(): void
    {
        $ctx = new EagerFlushRunContext();
        $ctx->enter();
        self::assertTrue($ctx->isActive());
    }

    public function testLeaveSetsActiveFalse(): void
    {
        $ctx = new EagerFlushRunContext();
        $ctx->enter();
        $ctx->leave();
        self::assertFalse($ctx->isActive());
    }

    public function testNestedEnterLeavePairsRequireMatchedLeaves(): void
    {
        $ctx = new EagerFlushRunContext();
        $ctx->enter();
        $ctx->enter();
        $ctx->leave();
        self::assertTrue($ctx->isActive(), 'Still active after one of two leaves');
        $ctx->leave();
        self::assertFalse($ctx->isActive());
    }

    public function testLeaveOnInactiveContextIsNoop(): void
    {
        $ctx = new EagerFlushRunContext();
        $ctx->leave();
        $ctx->leave();
        self::assertFalse($ctx->isActive());
    }
}
