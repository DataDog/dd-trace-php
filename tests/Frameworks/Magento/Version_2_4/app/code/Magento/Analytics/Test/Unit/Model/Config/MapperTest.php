<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Analytics\Test\Unit\Model\Config;

use Magento\Analytics\Model\Config\Mapper;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use PHPUnit\Framework\TestCase;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class MapperTest extends TestCase
{
    /**
     * @var ObjectManagerHelper
     */
    private $objectManagerHelper;

    /**
     * @var Mapper
     */
    private $mapper;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->objectManagerHelper = new ObjectManagerHelper($this);

        $this->mapper = $this->objectManagerHelper->getObject(Mapper::class);
    }

    /**
     * @param array $configData
     * @param array $resultData
     * @return void
     *
     * @dataProvider executingDataProvider
     */
    public function testExecution($configData, $resultData)
    {
        $this->assertSame($resultData, $this->mapper->execute($configData));
    }

    /**
     * @return array
     */
    public function executingDataProvider()
    {
        return [
            'wrongConfig' => [
                ['config' => ['files']],
                []
            ],
            'validConfigWithFileNodes' => [
                [
                    'config' => [
                        0 => [
                            'file' => [
                                0 => [
                                    'name' => 'fileName',
                                    'providers' => [[]]
                                ]
                            ]
                        ]
                    ]
                ],
                [
                    'fileName' => [
                        'name' => 'fileName',
                        'providers' => []
                    ]
                ],
            ],
            'validConfigWithProvidersNode' => [
                [
                    'config' => [
                        0 => [
                            'file' => [
                                0 => [
                                    'name' => 'fileName',
                                    'providers' => [
                                        0 => [
                                            'reportProvider' => [0 => []]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ],
                [
                    'fileName' => [
                        'name' => 'fileName',
                        'providers' => [
                            'reportProvider' => ['parameters' => []]
                        ]
                    ]
                ],
            ],
            'validConfigWithParametersNode' => [
                [
                    'config' => [
                        0 => [
                            'file' => [
                                0 => [
                                    'name' => 'fileName',
                                    'providers' => [
                                        0 => [
                                            'reportProvider' => [
                                                0 => [
                                                    'parameters' => [
                                                        0 => ['name' => ['reportName']]
                                                    ]
                                                ]
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ],
                [
                    'fileName' => [
                        'name' => 'fileName',
                        'providers' => [
                            'reportProvider' => [
                                'parameters' => [
                                    'name' => 'reportName'
                                ]
                            ]
                        ]
                    ]
                ],
            ],
        ];
    }
}
