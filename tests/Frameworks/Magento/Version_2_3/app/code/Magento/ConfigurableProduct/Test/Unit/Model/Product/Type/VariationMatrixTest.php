<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\ConfigurableProduct\Test\Unit\Model\Product\Type;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;

class VariationMatrixTest extends \PHPUnit\Framework\TestCase
{
    /** @var \Magento\ConfigurableProduct\Model\Product\Type\VariationMatrix */
    protected $model;

    /** @var ObjectManagerHelper */
    protected $objectManagerHelper;

    protected function setUp(): void
    {
        $this->objectManagerHelper = new ObjectManagerHelper($this);

        $this->model = $this->objectManagerHelper->getObject(
            \Magento\ConfigurableProduct\Model\Product\Type\VariationMatrix::class
        );
    }

    /**
     * Variations matrix test.
     *
     * @param array $expectedResult
     * @dataProvider variationProvider
     */
    public function testGetVariations($expectedResult)
    {

        $this->assertEquals($expectedResult['result'], $this->model->getVariations($expectedResult['input']));
    }

    /**
     * Test data provider.
     */
    public function variationProvider()
    {
        return [
            [
                'with_attribute_id' => [
                    'result' => [
                        [
                            130 => [
                                'value' => '3',
                                'label' => 'red',
                                'price' => [
                                    'value_index' => '3',
                                    'pricing_value' => '',
                                    'is_percent' => '0',
                                    'include' => '1'
                                ],
                            ],
                        ],
                        [
                            130 => [
                                'value' => '4',
                                'label' => 'blue',
                                'price' => [
                                    'value_index' => '4',
                                    'pricing_value' => '',
                                    'is_percent' => '0',
                                    'include' => '1'
                                ],
                            ],
                        ],
                    ],
                    'input' => [
                        130 => [
                            'values' => [
                                [
                                    'value_index' => '3',
                                    'pricing_value' => '',
                                    'is_percent' => '0',
                                    'include' => '1'
                                ],
                                [
                                    'value_index' => '4',
                                    'pricing_value' => '',
                                    'is_percent' => '0',
                                    'include' => '1'
                                ],
                            ],
                            'attribute_id' => '130',
                            'options' => [
                                [
                                    'value' => '3',
                                    'label' => 'red'
                                ],
                                ['value' => '4',
                                    'label' => 'blue'
                                ]
                            ],
                        ],
                    ]
                ],
                'without_attribute_id' => [
                    'result' => [
                        [
                            130 => [
                                'value' => '4',
                                'label' => 'blue',
                                'price' => [
                                    'value_index' => '4',
                                    'pricing_value' => '',
                                    'is_percent' => '0',
                                    'include' => '1'
                                ],
                            ],
                        ],
                    ],
                    'input' => [
                        130 => [
                            'values' => [
                                [
                                    'value_index' => '3',
                                    'pricing_value' => '',
                                    'is_percent' => '0',
                                    'include' => '1'
                                ]
                            ],
                            'attribute_id' => '',
                            'options' => [
                                [
                                    'value' => '3',
                                    'label' => 'red'
                                ]
                            ],
                        ],
                    ]
                ]
            ]
        ];
    }
}
