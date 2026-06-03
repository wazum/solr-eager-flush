<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Tests\Unit\Site;

use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use Wazum\SolrEagerFlush\Site\SiteConfigEagerFlushPolicy;

final class SiteEagerFlushPolicyTest extends TestCase
{
    public function testEnabledByDefaultWhenKeyAbsent(): void
    {
        $policy = new SiteConfigEagerFlushPolicy($this->siteFinderWithConfig([]));

        self::assertTrue($policy->isEnabledForRoot(1));
    }

    public function testDisabledWhenSiteConfigOptsOut(): void
    {
        $policy = new SiteConfigEagerFlushPolicy($this->siteFinderWithConfig(['solr_eager_flush_enabled' => false]));

        self::assertFalse($policy->isEnabledForRoot(1));
    }

    public function testEnabledWhenSiteConfigOptsIn(): void
    {
        $policy = new SiteConfigEagerFlushPolicy($this->siteFinderWithConfig(['solr_eager_flush_enabled' => true]));

        self::assertTrue($policy->isEnabledForRoot(1));
    }

    public function testEnabledWhenSiteCannotBeResolved(): void
    {
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getSiteByRootPageId')->willThrowException(new SiteNotFoundException('no site', 1));

        $policy = new SiteConfigEagerFlushPolicy($siteFinder);

        self::assertTrue($policy->isEnabledForRoot(1), 'Unresolvable site must not silently disable eager flush');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function siteFinderWithConfig(array $config): SiteFinder
    {
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getSiteByRootPageId')->willReturn(new Site('test', 1, $config + ['base' => '/']));

        return $siteFinder;
    }
}
