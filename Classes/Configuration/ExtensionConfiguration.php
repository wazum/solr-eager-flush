<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Configuration;

use InvalidArgumentException;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as CoreExtensionConfiguration;
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

    public static function from(CoreExtensionConfiguration $extensionConfiguration): self
    {
        try {
            $raw = $extensionConfiguration->get('solr_eager_flush');
        } catch (Throwable) {
            $raw = [];
        }
        $raw = \is_array($raw) ? $raw : [];

        $mode = TypeFilterMode::tryFrom((string) ($raw['typeFilter'] ?? 'records')) ?? TypeFilterMode::Records;

        $indexQueueLimit = (int) ($raw['indexQueueLimit'] ?? 5);
        $indexQueueLimit = $indexQueueLimit >= 1 ? $indexQueueLimit : 5;

        $deltaMax = (int) ($raw['deltaMax'] ?? 10);
        $deltaMax = $deltaMax >= 1 ? $deltaMax : 10;

        return new self(
            typeFilter: $mode,
            indexQueueLimit: $indexQueueLimit,
            deltaMax: max($deltaMax, $indexQueueLimit),
        );
    }
}
