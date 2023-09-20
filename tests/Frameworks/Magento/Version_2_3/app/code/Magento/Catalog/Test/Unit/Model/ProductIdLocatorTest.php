<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Catalog\Test\Unit\Model;

/**
 * Unit test for ProductIdLocator class.
 */
class ProductIdLocatorTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\EntityManager\MetadataPool|\PHPUnit\Framework\MockObject\MockObject
     */
    private $metadataPool;

    /**
     * @var \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    private $collectionFactory;

    /**
     * @var \Magento\Catalog\Model\ProductIdLocator
     */
    private $model;

    /**
     * Set up.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->metadataPool = $this->getMockBuilder(\Magento\Framework\EntityManager\MetadataPool::class)
            ->setMethods(['getMetadata'])
            ->disableOriginalConstructor()->getMock();
        $this->collectionFactory = $this
            ->getMockBuilder(\Magento\Catalog\Model\ResourceModel\Product\CollectionFactory::class)
            ->setMethods(['create'])
            ->disableOriginalConstructor()->getMock();

        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->model = $objectManager->getObject(
            \Magento\Catalog\Model\ProductIdLocator::class,
            [
                'metadataPool' => $this->metadataPool,
                'collectionFactory' => $this->collectionFactory,
            ]
        );
    }

    /**
     * Test retrieve
     */
    public function testRetrieveProductIdsBySkus()
    {
        $skus = ['sku_1', 'sku_2'];
        $collection = $this->getMockBuilder(\Magento\Catalog\Model\ResourceModel\Product\Collection::class)
            ->setMethods(
                [
                    'getItems',
                    'addFieldToFilter',
                    'setPageSize',
                    'getLastPageNumber',
                    'setCurPage',
                    'clear'
                ]
            )
            ->disableOriginalConstructor()->getMock();
        $product = $this->getMockBuilder(\Magento\Catalog\Api\Data\ProductInterface::class)
            ->setMethods(['getSku', 'getData', 'getTypeId'])
            ->disableOriginalConstructor()->getMockForAbstractClass();
        $metaDataInterface = $this->getMockBuilder(\Magento\Framework\EntityManager\EntityMetadataInterface::class)
            ->setMethods(['getLinkField'])
            ->disableOriginalConstructor()->getMockForAbstractClass();
        $this->collectionFactory->expects($this->once())->method('create')->willReturn($collection);
        $collection->expects($this->once())->method('addFieldToFilter')
            ->with(\Magento\Catalog\Api\Data\ProductInterface::SKU, ['in' => $skus])->willReturnSelf();
        $collection->expects($this->atLeastOnce())->method('getItems')->willReturn([$product]);
        $collection->expects($this->atLeastOnce())->method('setPageSize')->willReturnSelf();
        $collection->expects($this->atLeastOnce())->method('getLastPageNumber')->willReturn(1);
        $collection->expects($this->atLeastOnce())->method('setCurPage')->with(1)->willReturnSelf();
        $collection->expects($this->atLeastOnce())->method('clear')->willReturnSelf();
        $this->metadataPool
            ->expects($this->once())
            ->method('getMetadata')
            ->with(\Magento\Catalog\Api\Data\ProductInterface::class)
            ->willReturn($metaDataInterface);
        $metaDataInterface->expects($this->once())->method('getLinkField')->willReturn('entity_id');
        $product->expects($this->once())->method('getSku')->willReturn('sku_1');
        $product->expects($this->once())->method('getData')->with('entity_id')->willReturn(1);
        $product->expects($this->once())->method('getTypeId')->willReturn('simple');
        $this->assertEquals(
            ['sku_1' => [1 => 'simple']],
            $this->model->retrieveProductIdsBySkus($skus)
        );
    }
}
