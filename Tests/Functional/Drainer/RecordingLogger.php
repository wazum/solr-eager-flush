<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Tests\Functional\Drainer;

use Psr\Log\AbstractLogger;
use Stringable;

final class RecordingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $levels = [];

    /** @var list<string> */
    public array $messages = [];

    /** @var list<array<string, mixed>> */
    public array $contexts = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->levels[] = (string) $level;
        $this->messages[] = (string) $message;
        $this->contexts[] = $context;
    }
}
