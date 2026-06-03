<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Tests\Unit\Configuration;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as Core;
use Wazum\SolrEagerFlush\Configuration\ExtensionConfiguration;
use Wazum\SolrEagerFlush\TypeFilter\TypeFilterMode;

final class ExtensionConfigurationTest extends TestCase
{
    public function testParsesValidConfiguration(): void
    {
        $core = $this->createMock(Core::class);
        $core->method('get')->with('solr_eager_flush')->willReturn([
            'typeFilter' => 'both',
            'indexQueueLimit' => '5',
            'deltaMax' => '10',
        ]);

        $config = ExtensionConfiguration::fromCore($core);

        self::assertSame(TypeFilterMode::Both, $config->typeFilter);
        self::assertSame(5, $config->indexQueueLimit);
        self::assertSame(10, $config->deltaMax);
    }

    public function testAppliesDefaultsWhenKeysMissing(): void
    {
        $core = $this->createMock(Core::class);
        $core->method('get')->willReturn([]);

        $config = ExtensionConfiguration::fromCore($core);

        self::assertSame(TypeFilterMode::Both, $config->typeFilter);
        self::assertSame(5, $config->indexQueueLimit);
        self::assertSame(10, $config->deltaMax);
    }

    public function testThrowsOnUnknownTypeFilter(): void
    {
        $core = $this->createMock(Core::class);
        $core->method('get')->willReturn(['typeFilter' => 'banana']);

        $this->expectException(InvalidArgumentException::class);
        ExtensionConfiguration::fromCore($core);
    }

    public function testThrowsOnZeroIndexQueueLimit(): void
    {
        $core = $this->createMock(Core::class);
        $core->method('get')->willReturn(['indexQueueLimit' => '0']);

        $this->expectException(InvalidArgumentException::class);
        ExtensionConfiguration::fromCore($core);
    }

    public function testThrowsOnNegativeDeltaMax(): void
    {
        $core = $this->createMock(Core::class);
        $core->method('get')->willReturn(['deltaMax' => '-1']);

        $this->expectException(InvalidArgumentException::class);
        ExtensionConfiguration::fromCore($core);
    }
}
