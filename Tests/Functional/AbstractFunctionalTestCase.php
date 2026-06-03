<?php

declare(strict_types=1);

namespace Wazum\SolrEagerFlush\Tests\Functional;

use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

abstract class AbstractFunctionalTestCase extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'backend',
        'extbase',
        'fluid',
        'frontend',
        'install',
        'reports',
        'scheduler',
        'tstemplate',
    ];

    protected array $testExtensionsToLoad = [
        'apache-solr-for-typo3/solr',
        'wazum/solr-eager-flush',
    ];
}
