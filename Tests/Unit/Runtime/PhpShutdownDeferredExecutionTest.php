<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wazum\SolrEagerFlush\Runtime\PhpShutdownDeferredExecution;

final class PhpShutdownDeferredExecutionTest extends TestCase
{
    #[Test]
    public function reportsDeferralUnsupportedOnTheCommandLine(): void
    {
        self::assertFalse((new PhpShutdownDeferredExecution())->isSupported());
    }

    #[Test]
    public function doesNotInvokeTheDeferredTaskImmediately(): void
    {
        $invoked = false;

        (new PhpShutdownDeferredExecution())->defer(static function () use (&$invoked): void {
            $invoked = true;
        });

        self::assertFalse($invoked, 'The deferred task must only run at script shutdown');
    }
}
