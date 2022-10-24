<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Exception',
    'description' => 'Plugin to throw exception on page load',
    'category' => 'plugin',
    'clearCacheOnLoad' => true,
    'version' => '0.0.1',
    'constraints' => [
        'depends' => [
            'typo3' => '10.4.8-10.4.99',
        ],
    ],
];
