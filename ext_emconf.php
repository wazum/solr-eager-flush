<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Solr Eager Flush',
    'description' => 'Eager Solr index flush on save with pressure gating (ext:solr monitoringType=0)',
    'category' => 'plugin',
    'author' => 'Wolfgang Klinger',
    'author_email' => 'wolfgang@wazum.com',
    'state' => 'beta',
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.4.99',
            'scheduler' => '13.4.0-14.4.99',
            'solr' => '13.0.0-14.99.99',
        ],
    ],
];
