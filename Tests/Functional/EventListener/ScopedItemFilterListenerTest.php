<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Tests\Functional\EventListener;

use ApacheSolrForTypo3\Solr\Event\Indexing\BeforeItemsAreIndexedEvent;
use ApacheSolrForTypo3\Solr\IndexQueue\Item;
use PHPUnit\Framework\Attributes\Test;
use Wazum\SolrEagerFlush\Configuration\ExtensionConfiguration;
use Wazum\SolrEagerFlush\Drainer\EagerFlushRunContext;
use Wazum\SolrEagerFlush\EventListener\ScopedItemFilterListener;
use Wazum\SolrEagerFlush\Tests\Functional\AbstractFunctionalTestCase;
use Wazum\SolrEagerFlush\TypeFilter\ItemTypeMatcher;
use Wazum\SolrEagerFlush\TypeFilter\TypeFilterMode;

final class ScopedItemFilterListenerTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function passesThroughWhenRunContextIsInactive(): void
    {
        $pagesItem = $this->fakeItem('pages');
        $newsItem = $this->fakeItem('tx_news_domain_model_news');
        $event = new BeforeItemsAreIndexedEvent([$pagesItem, $newsItem], null, 'run-id');

        $this->buildListener(TypeFilterMode::Records)->__invoke($event);

        self::assertCount(2, $event->getItems(), 'Inactive RunContext means no filtering');
    }

    #[Test]
    public function filtersOutPagesWhenRecordsModeActive(): void
    {
        $pagesItem = $this->fakeItem('pages');
        $newsItem = $this->fakeItem('tx_news_domain_model_news');
        $event = new BeforeItemsAreIndexedEvent([$pagesItem, $newsItem], null, 'run-id');

        $runContext = new EagerFlushRunContext();
        $runContext->enter();
        try {
            $this->buildListener(TypeFilterMode::Records, $runContext)->__invoke($event);
        } finally {
            $runContext->leave();
        }

        self::assertCount(1, $event->getItems());
        self::assertSame('tx_news_domain_model_news', $event->getItems()[0]->getType());
    }

    #[Test]
    public function filtersOutRecordsWhenPagesModeActive(): void
    {
        $pagesItem = $this->fakeItem('pages');
        $newsItem = $this->fakeItem('tx_news_domain_model_news');
        $event = new BeforeItemsAreIndexedEvent([$pagesItem, $newsItem], null, 'run-id');

        $runContext = new EagerFlushRunContext();
        $runContext->enter();
        try {
            $this->buildListener(TypeFilterMode::Pages, $runContext)->__invoke($event);
        } finally {
            $runContext->leave();
        }

        self::assertCount(1, $event->getItems());
        self::assertSame('pages', $event->getItems()[0]->getType());
    }

    #[Test]
    public function bothModePassesEverythingThroughEvenWhenActive(): void
    {
        $pagesItem = $this->fakeItem('pages');
        $newsItem = $this->fakeItem('tx_news_domain_model_news');
        $event = new BeforeItemsAreIndexedEvent([$pagesItem, $newsItem], null, 'run-id');

        $runContext = new EagerFlushRunContext();
        $runContext->enter();
        try {
            $this->buildListener(TypeFilterMode::Both, $runContext)->__invoke($event);
        } finally {
            $runContext->leave();
        }

        self::assertCount(2, $event->getItems());
    }

    private function fakeItem(string $type): Item
    {
        $item = $this->createStub(Item::class);
        $item->method('getType')->willReturn($type);

        return $item;
    }

    private function buildListener(
        TypeFilterMode $mode,
        ?EagerFlushRunContext $runContext = null,
    ): ScopedItemFilterListener {
        return new ScopedItemFilterListener(
            $runContext ?? new EagerFlushRunContext(),
            new ItemTypeMatcher(),
            new ExtensionConfiguration(
                typeFilter: $mode,
                indexQueueLimit: 5,
                deltaMax: 10,
            ),
        );
    }
}
