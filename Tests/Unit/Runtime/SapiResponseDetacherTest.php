<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wazum\SolrEagerFlush\Runtime\SapiResponseDetacher;

final class SapiResponseDetacherTest extends TestCase
{
    #[Test]
    public function reportsNoDetachmentWhenTheSapiCannotReleaseTheClient(): void
    {
        self::assertFalse((new SapiResponseDetacher())->detach());
    }

    #[Test]
    public function enablesIgnoreUserAbortBeforeDetachingTheResponse(): void
    {
        $detacher = new class extends SapiResponseDetacher {
            public bool $abortEnabledWhenDetaching = false;

            protected function finishRequest(): bool
            {
                $this->abortEnabledWhenDetaching = (bool) ignore_user_abort();

                return true;
            }
        };

        self::assertTrue($detacher->detach());
        self::assertTrue(
            $detacher->abortEnabledWhenDetaching,
            'ignore_user_abort must be enabled before the response is detached',
        );
    }

    #[Test]
    public function restoresThePreviousIgnoreUserAbortWhenDetachmentIsNotPossible(): void
    {
        ignore_user_abort(false);
        $detacher = new class extends SapiResponseDetacher {
            protected function finishRequest(): bool
            {
                return false;
            }
        };

        self::assertFalse($detacher->detach());
        self::assertFalse((bool) ignore_user_abort(), 'the previous ignore_user_abort setting is restored');
    }
}
