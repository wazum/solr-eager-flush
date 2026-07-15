<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Tests\Unit\Configuration;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as CoreExtensionConfiguration;
use Wazum\SolrEagerFlush\Configuration\ExtensionConfiguration;
use Wazum\SolrEagerFlush\TypeFilter\TypeFilterMode;

final class ExtensionConfigurationTest extends TestCase
{
    #[Test]
    public function parsesValidConfiguration(): void
    {
        $coreExtensionConfiguration = $this->createMock(CoreExtensionConfiguration::class);
        $coreExtensionConfiguration->method('get')->with('solr_eager_flush')->willReturn([
            'typeFilter' => 'both',
            'indexQueueLimit' => '5',
            'deltaMax' => '10',
        ]);

        $configuration = ExtensionConfiguration::from($coreExtensionConfiguration);

        self::assertSame(TypeFilterMode::Both, $configuration->typeFilter);
        self::assertSame(5, $configuration->indexQueueLimit);
        self::assertSame(10, $configuration->deltaMax);
    }

    #[Test]
    public function appliesDefaultsWhenKeysMissing(): void
    {
        $coreExtensionConfiguration = $this->createMock(CoreExtensionConfiguration::class);
        $coreExtensionConfiguration->method('get')->willReturn([]);

        $configuration = ExtensionConfiguration::from($coreExtensionConfiguration);

        self::assertSame(TypeFilterMode::Records, $configuration->typeFilter);
        self::assertSame(5, $configuration->indexQueueLimit);
        self::assertSame(10, $configuration->deltaMax);
    }

    #[Test]
    public function fallsBackToDefaultTypeFilterOnUnknownValue(): void
    {
        $coreExtensionConfiguration = $this->createMock(CoreExtensionConfiguration::class);
        $coreExtensionConfiguration->method('get')->willReturn(['typeFilter' => 'banana']);

        $configuration = ExtensionConfiguration::from($coreExtensionConfiguration);

        self::assertSame(TypeFilterMode::Records, $configuration->typeFilter);
    }

    #[Test]
    public function fallsBackToDefaultIndexQueueLimitWhenBelowOne(): void
    {
        $coreExtensionConfiguration = $this->createMock(CoreExtensionConfiguration::class);
        $coreExtensionConfiguration->method('get')->willReturn(['indexQueueLimit' => '0']);

        $configuration = ExtensionConfiguration::from($coreExtensionConfiguration);

        self::assertSame(5, $configuration->indexQueueLimit);
    }

    #[Test]
    public function fallsBackToDefaultDeltaMaxWhenBelowOne(): void
    {
        $coreExtensionConfiguration = $this->createMock(CoreExtensionConfiguration::class);
        $coreExtensionConfiguration->method('get')->willReturn(['deltaMax' => '-1']);

        $configuration = ExtensionConfiguration::from($coreExtensionConfiguration);

        self::assertSame(10, $configuration->deltaMax);
    }

    #[Test]
    public function clampsDeltaMaxUpToIndexQueueLimit(): void
    {
        $coreExtensionConfiguration = $this->createMock(CoreExtensionConfiguration::class);
        $coreExtensionConfiguration->method('get')->willReturn(['indexQueueLimit' => '8', 'deltaMax' => '3']);

        $configuration = ExtensionConfiguration::from($coreExtensionConfiguration);

        self::assertSame(8, $configuration->indexQueueLimit);
        self::assertSame(8, $configuration->deltaMax, 'deltaMax is raised to indexQueueLimit to avoid type-filter starvation');
    }

    #[Test]
    public function constructorEnforcesPositiveBounds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ExtensionConfiguration(
            typeFilter: TypeFilterMode::Both,
            indexQueueLimit: 0,
            deltaMax: 10,
        );
    }

    #[Test]
    public function clampsIndexQueueLimitToTheMaximum(): void
    {
        $coreExtensionConfiguration = $this->createMock(CoreExtensionConfiguration::class);
        $coreExtensionConfiguration->method('get')->willReturn(['indexQueueLimit' => '100000']);

        $configuration = ExtensionConfiguration::from($coreExtensionConfiguration);

        self::assertSame(100, $configuration->indexQueueLimit);
    }

    #[Test]
    public function clampsDeltaMaxToTheMaximum(): void
    {
        $coreExtensionConfiguration = $this->createMock(CoreExtensionConfiguration::class);
        $coreExtensionConfiguration->method('get')->willReturn(['deltaMax' => '100000']);

        $configuration = ExtensionConfiguration::from($coreExtensionConfiguration);

        self::assertSame(100, $configuration->deltaMax);
    }

    #[Test]
    public function constructorRejectsValuesAboveTheMaximum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ExtensionConfiguration(
            typeFilter: TypeFilterMode::Both,
            indexQueueLimit: 101,
            deltaMax: 101,
        );
    }
}
