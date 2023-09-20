<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\CatalogWidget\Model\Rule\Condition;

use Magento\Catalog\Api\Data\ProductInterface;

class ProductTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\CatalogWidget\Model\Rule\Condition\Product
     */
    protected $conditionProduct;

    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $rule = $this->objectManager->create(\Magento\CatalogWidget\Model\Rule::class);
        $this->conditionProduct = $this->objectManager->create(
            \Magento\CatalogWidget\Model\Rule\Condition\Product::class
        );
        $this->conditionProduct->setRule($rule);
    }

    /**
     * @return void
     */
    public function testLoadAttributeOptions()
    {
        $this->conditionProduct->loadAttributeOptions();
        $options = $this->conditionProduct->getAttributeOption();
        $this->assertArrayHasKey(ProductInterface::SKU, $options);
        $this->assertArrayHasKey(ProductInterface::ATTRIBUTE_SET_ID, $options);
        $this->assertArrayHasKey('category_ids', $options);
        $this->assertArrayNotHasKey(ProductInterface::STATUS, $options);
        foreach ($options as $code => $label) {
            $this->assertNotEmpty($label);
            $this->assertNotEmpty($code);
        }
    }

    /**
     * @return void
     */
    public function testAddGlobalAttributeToCollection()
    {
        $collection = $this->objectManager->create(\Magento\Catalog\Model\ResourceModel\Product\Collection::class);
        $this->conditionProduct->setAttribute('special_price');
        $this->conditionProduct->addToCollection($collection);
        $collectedAttributes = $this->conditionProduct->getRule()->getCollectedAttributes();
        $this->assertArrayHasKey('special_price', $collectedAttributes);
        $query = (string)$collection->getSelect();
        $this->assertStringContainsString('special_price', $query);
        $this->assertEquals('at_special_price.value', $this->conditionProduct->getMappedSqlField());
    }

    /**
     * @return void
     */
    public function testAddNonGlobalAttributeToCollectionNoProducts()
    {
        $collection = $this->objectManager->create(\Magento\Catalog\Model\ResourceModel\Product\Collection::class);
        $this->conditionProduct->setAttribute('visibility');
        $this->conditionProduct->setOperator('()');
        $this->conditionProduct->setValue('4');
        $this->conditionProduct->addToCollection($collection);
        $collectedAttributes = $this->conditionProduct->getRule()->getCollectedAttributes();
        $this->assertArrayHasKey('visibility', $collectedAttributes);
        $query = (string)$collection->getSelect();
        $this->assertStringNotContainsString('visibility', $query);
        $this->assertEquals('', $this->conditionProduct->getMappedSqlField());
        $this->assertFalse($this->conditionProduct->hasValueParsed());
        $this->assertFalse($this->conditionProduct->hasValue());
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     */
    public function testAddNonGlobalAttributeToCollection()
    {
        $collection = $this->objectManager->create(\Magento\Catalog\Model\ResourceModel\Product\Collection::class);
        $this->conditionProduct->setAttribute('visibility');
        $this->conditionProduct->setOperator('()');
        $this->conditionProduct->setValue('4');
        $this->conditionProduct->addToCollection($collection);
        $collectedAttributes = $this->conditionProduct->getRule()->getCollectedAttributes();
        $this->assertArrayHasKey('visibility', $collectedAttributes);
        $query = (string)$collection->getSelect();
        $this->assertStringNotContainsString('visibility', $query);
        $this->assertEquals('e.entity_id', $this->conditionProduct->getMappedSqlField());
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     */
    public function testGetMappedSqlFieldCategoryIdsAttribute()
    {
        $this->conditionProduct->setAttribute('category_ids');
        $this->assertEquals('e.entity_id', $this->conditionProduct->getMappedSqlField());
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     */
    public function testGetMappedSqlFieldSkuAttribute()
    {
        $this->conditionProduct->setAttribute('sku');
        $this->assertEquals('e.sku', $this->conditionProduct->getMappedSqlField());
    }
}
