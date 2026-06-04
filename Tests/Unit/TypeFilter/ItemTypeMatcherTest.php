<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Tests\Unit\TypeFilter;

use ApacheSolrForTypo3\Solr\IndexQueue\Item;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wazum\SolrEagerFlush\TypeFilter\ItemTypeMatcher;
use Wazum\SolrEagerFlush\TypeFilter\TypeFilterMode;

final class ItemTypeMatcherTest extends TestCase
{
    private ItemTypeMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new ItemTypeMatcher();
    }

    #[Test]
    public function bothMatchesEverything(): void
    {
        self::assertTrue($this->matcher->matches($this->fakeItem('pages'), TypeFilterMode::Both));
        self::assertTrue($this->matcher->matches($this->fakeItem('tx_news_domain_model_news'), TypeFilterMode::Both));
    }

    #[Test]
    public function pagesMatchesOnlyPages(): void
    {
        self::assertTrue($this->matcher->matches($this->fakeItem('pages'), TypeFilterMode::Pages));
        self::assertFalse($this->matcher->matches($this->fakeItem('tx_news_domain_model_news'), TypeFilterMode::Pages));
    }

    #[Test]
    public function recordsMatchesEverythingExceptPages(): void
    {
        self::assertFalse($this->matcher->matches($this->fakeItem('pages'), TypeFilterMode::Records));
        self::assertTrue($this->matcher->matches($this->fakeItem('tx_news_domain_model_news'), TypeFilterMode::Records));
        self::assertTrue($this->matcher->matches($this->fakeItem('tt_address'), TypeFilterMode::Records));
    }

    private function fakeItem(string $type): Item
    {
        $item = $this->createStub(Item::class);
        $item->method('getType')->willReturn($type);

        return $item;
    }
}
