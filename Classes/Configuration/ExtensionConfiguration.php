<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Configuration;

use InvalidArgumentException;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as Core;
use Wazum\SolrEagerFlush\TypeFilter\TypeFilterMode;

final readonly class ExtensionConfiguration
{
    public function __construct(
        public TypeFilterMode $typeFilter,
        public int $indexQueueLimit,
        public int $deltaMax,
    ) {
        if ($indexQueueLimit < 1 || $deltaMax < 1) {
            throw new InvalidArgumentException('indexQueueLimit and deltaMax must both be >= 1');
        }
    }

    public static function fromCore(Core $core): self
    {
        try {
            $raw = $core->get('solr_eager_flush');
        } catch (Throwable) {
            $raw = [];
        }
        $raw = \is_array($raw) ? $raw : [];

        $mode = TypeFilterMode::tryFrom((string) ($raw['typeFilter'] ?? 'both')) ?? TypeFilterMode::Both;
        $indexQueueLimit = (int) ($raw['indexQueueLimit'] ?? 5);
        $deltaMax = (int) ($raw['deltaMax'] ?? 10);

        return new self(
            typeFilter: $mode,
            indexQueueLimit: $indexQueueLimit >= 1 ? $indexQueueLimit : 5,
            deltaMax: $deltaMax >= 1 ? $deltaMax : 10,
        );
    }
}
