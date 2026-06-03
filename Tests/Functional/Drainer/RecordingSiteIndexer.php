<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Tests\Functional\Drainer;

use Wazum\SolrEagerFlush\Indexing\SiteIndexer;

final class RecordingSiteIndexer implements SiteIndexer
{
    /** @var list<int> */
    public array $attempted = [];

    public function index(int $rootPageId, int $limit): bool
    {
        $this->attempted[] = $rootPageId;

        return true;
    }
}
