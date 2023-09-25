<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
return [
    'preferences' => [
        'Magento\Framework\Module\SomeInterface' => 'Magento\Framework\Module\ClassOne',
        'Magento\Framework\App\RequestInterface' => 'Magento\Framework\App\Request\Http\Proxy',
    ],
    'Magento\Framework\App\State' => ['arguments' => ['test name' => 'test value']],
    'Magento\Config\Model\Config\Modules' => [
        'arguments' => ['test name' => 'test value'],
        'plugins' => [
            'simple_modules_plugin' => [
                'sortOrder' => 10,
                'disabled' => true,
                'instance' => 'Magento\Config\Model\Config\Modules\Plugin',
            ],
            'simple_modules_plugin_advanced' => [
                'sortOrder' => 0,
                'instance' => 'Magento\Config\Model\Config\Modules\PluginAdvanced',
            ],
            'overridden_plugin' => ['sortOrder' => 30, 'disabled' => true],
        ],
    ],
    'Magento\SomeComponent\UnsharedType' => [
        'shared' => false,
        'arguments' => ['test name' => 'test value'],
    ],
    'customCacheInstance' => [
        'shared' => true,
        'type' => \Magento\Framework\App\Cache::class,
        'arguments' => [],
    ],
    'customOverriddenInstance' => [
        'shared' => false,
        'arguments' => [],
    ]
];
