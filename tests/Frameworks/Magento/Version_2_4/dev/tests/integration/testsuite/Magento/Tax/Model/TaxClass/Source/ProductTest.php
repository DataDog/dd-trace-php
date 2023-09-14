<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Tax\Model\TaxClass\Source;

use Magento\TestFramework\Helper\Bootstrap;

class ProductTest extends \PHPUnit\Framework\TestCase
{
    public function testGetAllOptions()
    {
        /** @var \Magento\Tax\Model\ResourceModel\TaxClass\Collection $collection */
        $collection = Bootstrap::getObjectManager()->get(\Magento\Tax\Model\ResourceModel\TaxClass\Collection::class);
        $expectedResult = [];
        /** @var \Magento\Tax\Model\ClassModel $taxClass */
        foreach ($collection as $taxClass) {
            if ($taxClass->getClassType() == \Magento\Tax\Api\TaxClassManagementInterface::TYPE_PRODUCT) {
                $expectedResult[] = ['value' => $taxClass->getId(), 'label' => $taxClass->getClassName()];
            }
        }
        if (empty($expectedResult)) {
            $this->fail('Preconditions failed: At least one tax class should be available.');
        }
        /** @var \Magento\Tax\Model\TaxClass\Source\Product $source */
        $source = Bootstrap::getObjectManager()->get(\Magento\Tax\Model\TaxClass\Source\Product::class);
        $this->assertEquals(
            $expectedResult,
            $source->getAllOptions(false),
            'Tax Class options are invalid.'
        );
    }

    public function testGetAllOptionsWithDefaultValues()
    {
        /** @var \Magento\Tax\Model\ResourceModel\TaxClass\Collection $collection */
        $collection = Bootstrap::getObjectManager()->get(\Magento\Tax\Model\ResourceModel\TaxClass\Collection::class);
        $expectedResult = [];
        /** @var \Magento\Tax\Model\ClassModel $taxClass */
        foreach ($collection as $taxClass) {
            if ($taxClass->getClassType() == \Magento\Tax\Api\TaxClassManagementInterface::TYPE_PRODUCT) {
                $expectedResult[] = ['value' => $taxClass->getId(), 'label' => $taxClass->getClassName()];
            }
        }
        if (empty($expectedResult)) {
            $this->fail('Preconditions failed: At least one tax class should be available.');
        }
        $expectedResult = array_merge([['value' => '0', 'label' => __('None')]], $expectedResult);
        /** @var \Magento\Tax\Model\TaxClass\Source\Product $source */
        $source = Bootstrap::getObjectManager()->get(\Magento\Tax\Model\TaxClass\Source\Product::class);
        $this->assertEquals(
            $expectedResult,
            $source->getAllOptions(true),
            'Tax Class options are invalid.'
        );
    }
}
