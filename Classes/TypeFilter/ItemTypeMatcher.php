<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\TypeFilter;

use ApacheSolrForTypo3\Solr\IndexQueue\Item;

final readonly class ItemTypeMatcher
{
    public function matches(Item $item, TypeFilterMode $mode): bool
    {
        return match ($mode) {
            TypeFilterMode::Both => true,
            TypeFilterMode::Pages => $item->getType() === 'pages',
            TypeFilterMode::Records => $item->getType() !== 'pages',
        };
    }
}
