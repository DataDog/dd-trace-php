<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Swatches\Test\Unit\Block\Adminhtml\Product\Attribute\Edit;

use Magento\Swatches\Model\Swatch;

class FormTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @dataProvider dataForAddValues
     */
    public function testAddValues($values)
    {
        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $block = $objectManager->getObject(\Magento\Swatches\Block\Adminhtml\Product\Attribute\Edit\Form::class);
        $result= $block->addValues($values);
        $this->assertEquals($block, $result);
    }

    /**
     * @return array
     */
    public function dataForAddValues()
    {
        $additionalData = [
            'swatch_input_type' => 'visual',
            'update_product_preview_image' => 1,
            'use_product_image_for_swatch' => 0
        ];

        return [
            [
                [
                    'frontend_input' => 'select',
                    'swatch_input_type' => 'text',
                ]
            ],
            [
                [
                    'frontend_input' => 'textarea',
                ]
            ],
            [
                [
                    'frontend_input' => 'select',
                ]
            ],
            [
                'wrong_string_value',
            ],
            [
                [
                    'additional_data' => json_encode($additionalData),
                    'frontend_input' => 'select',
                ]
            ],
            [
                [
                    'additional_data' => '',
                    'frontend_input' => 'select',
                ]
            ],
        ];
    }
}
