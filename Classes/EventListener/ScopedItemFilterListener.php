<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\EventListener;

use ApacheSolrForTypo3\Solr\Event\Indexing\BeforeItemsAreIndexedEvent;
use Wazum\SolrEagerFlush\Configuration\ExtensionConfiguration;
use Wazum\SolrEagerFlush\Drainer\EagerFlushRunContext;
use Wazum\SolrEagerFlush\TypeFilter\ItemTypeMatcher;

final readonly class ScopedItemFilterListener
{
    public function __construct(
        private EagerFlushRunContext $runContext,
        private ItemTypeMatcher $matcher,
        private ExtensionConfiguration $config,
    ) {}

    public function __invoke(BeforeItemsAreIndexedEvent $event): void
    {
        if (!$this->runContext->isActive()) {
            return;
        }

        $filtered = array_values(array_filter(
            $event->getItems(),
            fn ($item) => $this->matcher->matches($item, $this->config->typeFilter),
        ));
        $event->setItems($filtered);
    }
}
