<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Tests\Unit\Site;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use RuntimeException;
use Stringable;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use Wazum\SolrEagerFlush\Site\SiteConfigEagerFlushPolicy;

final class SiteEagerFlushPolicyTest extends TestCase
{
    #[Test]
    public function enabledByDefaultWhenKeyAbsent(): void
    {
        self::assertTrue($this->policy([])->isEnabledForRoot(1));
    }

    #[Test]
    public function disabledWhenSiteConfigOptsOut(): void
    {
        self::assertFalse($this->policy(['solr_eager_flush_enabled' => false])->isEnabledForRoot(1));
    }

    #[Test]
    public function disabledWhenSiteConfigOptsOutWithStringValue(): void
    {
        self::assertFalse(
            $this->policy(['solr_eager_flush_enabled' => 'false'])->isEnabledForRoot(1),
            "A quoted YAML 'false' must disable eager flush, not enable it",
        );
    }

    #[Test]
    public function enabledWhenSiteConfigOptsIn(): void
    {
        self::assertTrue($this->policy(['solr_eager_flush_enabled' => true])->isEnabledForRoot(1));
    }

    #[Test]
    public function disabledAndLogsWhenSiteCannotBeResolved(): void
    {
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getSiteByRootPageId')->willThrowException(new SiteNotFoundException('no site', 1));
        $logger = $this->recordingLogger();

        $policy = new SiteConfigEagerFlushPolicy($siteFinder, $logger);

        self::assertFalse($policy->isEnabledForRoot(4), 'An unresolvable site must fail closed');
        self::assertContains(
            LogLevel::WARNING,
            $logger->levels,
            'A queued root without a site is a broken state, not a silent opt-out',
        );
        self::assertSame(4, $logger->contexts[0]['root']);
    }

    #[Test]
    public function disabledAndLogsWhenSiteConfigCannotBeRead(): void
    {
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getSiteByRootPageId')->willThrowException(new RuntimeException('database is down'));
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $levels = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->levels[] = (string) $level;
            }
        };

        $policy = new SiteConfigEagerFlushPolicy($siteFinder, $logger);

        self::assertFalse($policy->isEnabledForRoot(1), 'An unreadable site configuration must fail closed');
        self::assertContains(LogLevel::WARNING, $logger->levels, 'An unexpected site lookup error must be logged');
    }

    /**
     * @return AbstractLogger&object{levels: list<string>, contexts: list<array<string, mixed>>}
     */
    private function recordingLogger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<string> */
            public array $levels = [];

            /** @var list<array<string, mixed>> */
            public array $contexts = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->levels[] = (string) $level;
                $this->contexts[] = $context;
            }
        };
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function policy(array $configuration, LoggerInterface $logger = new NullLogger()): SiteConfigEagerFlushPolicy
    {
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getSiteByRootPageId')->willReturn(new Site('test', 1, $configuration + ['base' => '/']));

        return new SiteConfigEagerFlushPolicy($siteFinder, $logger);
    }
}
