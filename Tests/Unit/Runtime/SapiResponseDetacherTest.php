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
}
