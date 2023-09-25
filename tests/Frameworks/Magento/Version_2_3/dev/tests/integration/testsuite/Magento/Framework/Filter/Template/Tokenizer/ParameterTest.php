<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Filter\Template\Tokenizer;

class ParameterTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @param string $string
     * @param array $values
     * @dataProvider getValueDataProvider
     */
    public function testGetValue($string, $values)
    {
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        /** @var \Magento\Framework\Filter\Template\Tokenizer\Parameter $parameter */
        $parameter = $objectManager->create(\Magento\Framework\Filter\Template\Tokenizer\Parameter::class);
        $parameter->setString($string);

        foreach ($values as $value) {
            $this->assertEquals($value, $parameter->getValue());
        }
    }

    /**
     * @dataProvider tokenizeDataProvider
     * @param string $string
     * @param array $params
     */
    public function testTokenize($string, $params)
    {
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        /** @var \Magento\Framework\Filter\Template\Tokenizer\Parameter $parameter */
        $parameter = $objectManager->create(\Magento\Framework\Filter\Template\Tokenizer\Parameter::class);
        $parameter->setString($string);
        $this->assertEquals($params, $parameter->tokenize());
    }

    /**
     * @return array
     */
    public function tokenizeDataProvider()
    {
        return [
            [
                ' type="Magento\\Catalog\\Block\\Product\\Widget\\NewWidget" display_type="all_products"'
                . ' products_count="10" template="product/widget/new/content/new_grid.phtml"',
                [
                    'type' => \Magento\Catalog\Block\Product\Widget\NewWidget::class,
                    'display_type' => 'all_products',
                    'products_count' => 10,
                    'template' => 'product/widget/new/content/new_grid.phtml'
                ],
            ],
            [
                ' type="Magento\Catalog\Block\Product\Widget\NewWidget" display_type="all_products"'
                . ' products_count="10" template="product/widget/new/content/new_grid.phtml"',
                [
                    'type' => \Magento\Catalog\Block\Product\Widget\NewWidget::class,
                    'display_type' => 'all_products',
                    'products_count' => 10,
                    'template' => 'product/widget/new/content/new_grid.phtml'
                ]
            ]
        ];
    }

    /**
     * @return array
     */
    public function getValueDataProvider()
    {
        return [
            [
                ' type="Magento\\Catalog\\Block\\Product\\Widget\\NewWidget" display_type="all_products"'
                . ' products_count="10" template="product/widget/new/content/new_grid.phtml"',
                [
                    'type="Magento\Catalog\Block\Product\Widget\NewWidget"',
                    'display_type="all_products"',
                    'products_count="10"'
                ],
            ],
            [
                ' type="Magento\Catalog\Block\Product\Widget\NewWidget" display_type="all_products"'
                . ' products_count="10" template="product/widget/new/content/new_grid.phtml"',
                [
                    'type="Magento\Catalog\Block\Product\Widget\NewWidget"',
                    'display_type="all_products"',
                    'products_count="10"'
                ]
            ]
        ];
    }
}
