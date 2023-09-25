<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Model\Product\Attribute\Backend;

/**
 * Test class for \Magento\Catalog\Model\Product\Attribute\Backend\Sku.
 * @magentoAppArea adminhtml
 */
class SkuTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     */
    public function testGenerateUniqueSkuExistingProduct()
    {
        $repository = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
            \Magento\Catalog\Model\ProductRepository::class
        );
        $product = $repository->get('simple');
        $product->setId(null);
        $this->assertEquals('simple', $product->getSku());
        $product->getResource()->getAttribute('sku')->getBackend()->beforeSave($product);
        $this->assertEquals('simple-1', $product->getSku());
    }

    /**
     * @param $product \Magento\Catalog\Model\Product
     * @dataProvider uniqueSkuDataProvider
     */
    public function testGenerateUniqueSkuNotExistingProduct($product)
    {
        $this->assertEquals('simple', $product->getSku());
        $product->getResource()->getAttribute('sku')->getBackend()->beforeSave($product);
        $this->assertEquals('simple', $product->getSku());
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     * @magentoAppArea adminhtml
     * @magentoDbIsolation enabled
     */
    public function testGenerateUniqueLongSku()
    {
        $repository = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
            \Magento\Catalog\Model\ProductRepository::class
        );
        $product = $repository->get('simple');
        $product->setSku('0123456789012345678901234567890123456789012345678901234567890123');

        /** @var \Magento\Catalog\Model\Product\Copier $copier */
        $copier = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(
            \Magento\Catalog\Model\Product\Copier::class
        );
        $copier->copy($product);
        $this->assertEquals('0123456789012345678901234567890123456789012345678901234567890123', $product->getSku());
        $product->getResource()->getAttribute('sku')->getBackend()->beforeSave($product);
        $this->assertEquals('01234567890123456789012345678901234567890123456789012345678901-1', $product->getSku());
    }

    /**
     * Returns simple product
     *
     * @return array
     */
    public function uniqueSkuDataProvider()
    {
        $product = $this->_getProduct();
        return [[$product]];
    }

    /**
     * Get product form data provider
     *
     * @return \Magento\Catalog\Model\Product
     */
    protected function _getProduct()
    {
        /** @var $product \Magento\Catalog\Model\Product */
        $product = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
            \Magento\Catalog\Model\Product::class
        );
        $product->setTypeId(
            \Magento\Catalog\Model\Product\Type::TYPE_SIMPLE
        )->setId(
            1
        )->setAttributeSetId(
            4
        )->setWebsiteIds(
            [1]
        )->setName(
            'Simple Product'
        )->setSku(
            'simple'
        )->setPrice(
            10
        )->setDescription(
            'Description with <b>html tag</b>'
        )->setVisibility(
            \Magento\Catalog\Model\Product\Visibility::VISIBILITY_BOTH
        )->setStatus(
            \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED
        )->setCategoryIds(
            [2]
        )->setStockData(
            ['use_config_manage_stock' => 1, 'qty' => 100, 'is_qty_decimal' => 0, 'is_in_stock' => 1]
        );
        return $product;
    }
}
