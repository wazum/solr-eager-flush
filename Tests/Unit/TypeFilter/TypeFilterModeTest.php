<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Tests\Unit\TypeFilter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wazum\SolrEagerFlush\TypeFilter\TypeFilterMode;

final class TypeFilterModeTest extends TestCase
{
    #[Test]
    public function enumHasThreeCases(): void
    {
        self::assertSame('records', TypeFilterMode::Records->value);
        self::assertSame('pages', TypeFilterMode::Pages->value);
        self::assertSame('both', TypeFilterMode::Both->value);
    }

    #[Test]
    public function fromValidStringReturnsCase(): void
    {
        self::assertSame(TypeFilterMode::Records, TypeFilterMode::from('records'));
        self::assertSame(TypeFilterMode::Pages, TypeFilterMode::from('pages'));
        self::assertSame(TypeFilterMode::Both, TypeFilterMode::from('both'));
    }

    #[Test]
    public function tryFromInvalidStringReturnsNull(): void
    {
        self::assertNull(TypeFilterMode::tryFrom('invalid'));
    }
}
