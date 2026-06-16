<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Solr Eager Flush',
    'description' => 'Eager Solr indexing on save for TYPO3 (ext:solr)',
    'category' => 'plugin',
    'author' => 'Wolfgang Klinger',
    'author_email' => 'wolfgang@wazum.com',
    'state' => 'stable',
    'version' => '1.3.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.4.99',
            'scheduler' => '13.4.0-14.4.99',
            'solr' => '13.0.0-14.99.99',
        ],
    ],
];
