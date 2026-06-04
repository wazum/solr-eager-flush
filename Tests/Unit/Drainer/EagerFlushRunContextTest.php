<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Tests\Unit\Drainer;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wazum\SolrEagerFlush\Drainer\EagerFlushRunContext;

final class EagerFlushRunContextTest extends TestCase
{
    #[Test]
    public function startsInactive(): void
    {
        self::assertFalse((new EagerFlushRunContext())->isActive());
    }

    #[Test]
    public function enterSetsActiveTrue(): void
    {
        $ctx = new EagerFlushRunContext();
        $ctx->enter();
        self::assertTrue($ctx->isActive());
    }

    #[Test]
    public function leaveSetsActiveFalse(): void
    {
        $ctx = new EagerFlushRunContext();
        $ctx->enter();
        $ctx->leave();
        self::assertFalse($ctx->isActive());
    }

    #[Test]
    public function nestedEnterLeavePairsRequireMatchedLeaves(): void
    {
        $ctx = new EagerFlushRunContext();
        $ctx->enter();
        $ctx->enter();
        $ctx->leave();
        self::assertTrue($ctx->isActive(), 'Still active after one of two leaves');
        $ctx->leave();
        self::assertFalse($ctx->isActive());
    }

    #[Test]
    public function leaveOnInactiveContextIsNoop(): void
    {
        $ctx = new EagerFlushRunContext();
        $ctx->leave();
        $ctx->leave();
        self::assertFalse($ctx->isActive());
    }
}
