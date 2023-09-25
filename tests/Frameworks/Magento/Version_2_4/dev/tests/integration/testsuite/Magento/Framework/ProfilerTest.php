<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework;

use ReflectionClass;

class ProfilerTest extends \PHPUnit\Framework\TestCase
{
    protected function tearDown(): void
    {
        \Magento\Framework\Profiler::reset();
    }

    /**
     * @dataProvider applyConfigDataProvider
     * @param array $config
     * @param array $expectedDrivers
     */
    public function testApplyConfigWithDrivers(array $config, array $expectedDrivers)
    {
        $profiler = new \Magento\Framework\Profiler();
        $profiler::applyConfig($config, '');
        $this->assertIsObject($profiler);
        $this->assertTrue(property_exists($profiler, '_drivers'));
        $object = new ReflectionClass(\Magento\Framework\Profiler::class);
        $attribute = $object->getProperty('_drivers');
        $attribute->setAccessible(true);
        $propertyObject = $attribute->getValue($profiler);
        $attribute->setAccessible(false);
        $this->assertEquals($expectedDrivers, $propertyObject);
    }

    /**
     * @return array
     */
    public function applyConfigDataProvider()
    {
        return [
            'Empty config does not create any driver' => ['config' => [], 'drivers' => []],
            'Integer 0 does not create any driver' => [
                'config' => ['drivers' => [0]],
                'drivers' => [],
            ],
            'Integer 1 does creates standard driver' => [
                'config' => ['drivers' => [1]],
                'drivers' => [new \Magento\Framework\Profiler\Driver\Standard()],
            ],
            'Config array key sets driver type' => [
                'configs' => ['drivers' => ['standard' => 1]],
                'drivers' => [new \Magento\Framework\Profiler\Driver\Standard()],
            ],
            'Config array key ignored when type set' => [
                'config' => ['drivers' => ['custom' => ['type' => 'standard']]],
                'drivers' => [new \Magento\Framework\Profiler\Driver\Standard()],
            ],
            'Config with outputs element as integer 1 creates output' => [
                'config' => [
                    'drivers' => [['outputs' => ['html' => 1]]],
                    'baseDir' => '/some/base/dir',
                ],
                'drivers' => [
                    new \Magento\Framework\Profiler\Driver\Standard(
                        ['outputs' => [['type' => 'html', 'baseDir' => '/some/base/dir']]]
                    ),
                ],
            ],
            'Config with outputs element as integer 0 does not create output' => [
                'config' => ['drivers' => [['outputs' => ['html' => 0]]]],
                'drivers' => [new \Magento\Framework\Profiler\Driver\Standard()],
            ],
            'Config with shortly defined outputs element' => [
                'config' => ['drivers' => [['outputs' => ['foo' => 'html']]]],
                'drivers' => [
                    new \Magento\Framework\Profiler\Driver\Standard(['outputs' => [['type' => 'html']]]),
                ],
            ],
            'Config with fully defined outputs element options' => [
                'config' => [
                    'drivers' => [
                        [
                            'outputs' => [
                                'foo' => [
                                    'type' => 'html',
                                    'filterName' => '/someFilter/',
                                    'thresholds' => ['someKey' => 123],
                                    'baseDir' => '/custom/dir',
                                ],
                            ],
                        ],
                    ],
                ],
                'drivers' => [
                    new \Magento\Framework\Profiler\Driver\Standard(
                        [
                            'outputs' => [
                                [
                                    'type' => 'html',
                                    'filterName' => '/someFilter/',
                                    'thresholds' => ['someKey' => 123],
                                    'baseDir' => '/custom/dir',
                                ],
                            ],
                        ]
                    ),
                ],
            ],
            'Config with shortly defined output' => [
                'config' => ['drivers' => [['output' => 'html']]],
                'drivers' => [
                    new \Magento\Framework\Profiler\Driver\Standard(['outputs' => [['type' => 'html']]]),
                ],
            ]
        ];
    }
}
