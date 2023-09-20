<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

use Magento\Setup\Mvc\Bootstrap\InitParamListener;
use Laminas\Mvc\Service\DiAbstractServiceFactoryFactory;
use Laminas\ServiceManager\Di\DiAbstractServiceFactory;

return [
    'modules' => [
        'Magento\Setup',
    ],
    'module_listener_options' => [
        'module_paths' => [
            __DIR__ . '/../src',
        ],
        'config_glob_paths' => [
            __DIR__ . '/autoload/{,*.}{global,local}.php',
        ],
    ],
    'listeners' => [
        InitParamListener::class
    ],
    'service_manager' => [
        'factories' => [
            DiAbstractServiceFactory::class => DiAbstractServiceFactoryFactory::class,
            InitParamListener::BOOTSTRAP_PARAM => InitParamListener::class,
        ],
    ],
    // list of Magento specific required services, like default abstract factory
    'required_services' => [
        DiAbstractServiceFactory::class
    ]
];
