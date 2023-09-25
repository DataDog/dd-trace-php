<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Test class for \Magento\ImportExport\Model\Import\Entity\AbstractEav
 */
namespace Magento\ImportExport\Model\Import\Entity;

class EavAbstractTest extends \PHPUnit\Framework\TestCase
{
    /**
     * Model object which used for tests
     *
     * @var \Magento\ImportExport\Model\Import\Entity\AbstractEav|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $_model;

    /**
     * Create all necessary data for tests
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->_model = $this->getMockForAbstractClass(
            \Magento\ImportExport\Model\Import\Entity\AbstractEav::class,
            [],
            '',
            false
        );
    }

    /**
     * Test for method getAttributeOptions()
     */
    public function testGetAttributeOptions()
    {
        $indexAttributeCode = 'gender';

        /** @var $attributeCollection \Magento\Customer\Model\ResourceModel\Attribute\Collection */
        $attributeCollection = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
            \Magento\Customer\Model\ResourceModel\Attribute\Collection::class
        );
        $attributeCollection->addFieldToFilter(
            'attribute_code',
            ['in' => [$indexAttributeCode, 'group_id']]
        );
        /** @var $attribute \Magento\Customer\Model\Attribute */
        foreach ($attributeCollection as $attribute) {
            $index = $attribute->getAttributeCode() == $indexAttributeCode ? 'value' : 'label';
            $expectedOptions = [];
            foreach ($attribute->getSource()->getAllOptions(false) as $option) {
                if (is_array($option['value'])) {
                    foreach ($option['value'] as $value) {
                        $expectedOptions[strtolower($value[$index])] = $value['value'];
                    }
                } else {
                    $expectedOptions[strtolower($option[$index])] = $option['value'];
                }
            }
            $actualOptions = $this->_model->getAttributeOptions($attribute, [$indexAttributeCode]);
            asort($expectedOptions);
            asort($actualOptions);
            $this->assertEquals($expectedOptions, $actualOptions);
        }
    }
}
