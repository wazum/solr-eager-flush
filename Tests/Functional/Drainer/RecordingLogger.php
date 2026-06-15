<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Tests\Functional\Drainer;

use Psr\Log\AbstractLogger;
use Stringable;

final class RecordingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $levels = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->levels[] = (string) $level;
    }
}
