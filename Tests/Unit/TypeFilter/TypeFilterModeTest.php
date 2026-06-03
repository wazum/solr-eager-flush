<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Tests\Unit\TypeFilter;

use PHPUnit\Framework\TestCase;
use Wazum\SolrEagerFlush\TypeFilter\TypeFilterMode;

final class TypeFilterModeTest extends TestCase
{
    public function testEnumHasThreeCases(): void
    {
        self::assertSame('records', TypeFilterMode::Records->value);
        self::assertSame('pages', TypeFilterMode::Pages->value);
        self::assertSame('both', TypeFilterMode::Both->value);
    }

    public function testFromValidStringReturnsCase(): void
    {
        self::assertSame(TypeFilterMode::Records, TypeFilterMode::from('records'));
        self::assertSame(TypeFilterMode::Pages, TypeFilterMode::from('pages'));
        self::assertSame(TypeFilterMode::Both, TypeFilterMode::from('both'));
    }

    public function testTryFromInvalidStringReturnsNull(): void
    {
        self::assertNull(TypeFilterMode::tryFrom('invalid'));
    }
}
