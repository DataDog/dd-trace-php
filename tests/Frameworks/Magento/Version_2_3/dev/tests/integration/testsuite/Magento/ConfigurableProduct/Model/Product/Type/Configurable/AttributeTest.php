<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\ConfigurableProduct\Model\Product\Type\Configurable;

class AttributeTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\ConfigurableProduct\Model\Product\Type\Configurable\Attribute
     */
    protected $_model;

    protected function setUp(): void
    {
        $this->_model = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
            \Magento\ConfigurableProduct\Model\Product\Type\Configurable\Attribute::class
        );
    }

    public function testGetLabel()
    {
        $this->assertEmpty($this->_model->getLabel());
        $this->_model->setProductAttribute(new \Magento\Framework\DataObject(['store_label' => 'Store Label']));
        $this->assertEquals('Store Label', $this->_model->getLabel());

        $this->_model->setUseDefault(
            1
        )->setProductAttribute(
            new \Magento\Framework\DataObject(['store_label' => 'Other Label'])
        );
        $this->assertEquals('Other Label', $this->_model->getLabel());
    }
}
