<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\CatalogInventory\Test\Unit\Block\Stockqty;

/**
 * Unit test for DefaultStockqty
 */
class DefaultStockqtyTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\CatalogInventory\Block\Stockqty\DefaultStockqty
     */
    protected $block;

    /**
     * @var \Magento\Framework\Registry|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $registryMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $stockRegistryMock;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $scopeConfigMock;

    protected function setUp(): void
    {
        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->registryMock = $this->createMock(\Magento\Framework\Registry::class);
        $this->stockRegistryMock = $this->getMockBuilder(\Magento\CatalogInventory\Api\StockRegistryInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->scopeConfigMock = $this->getMockBuilder(\Magento\Framework\App\Config\ScopeConfigInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->block = $objectManager->getObject(
            \Magento\CatalogInventory\Block\Stockqty\DefaultStockqty::class,
            [
                'registry' => $this->registryMock,
                'stockRegistry' => $this->stockRegistryMock,
                'scopeConfig' => $this->scopeConfigMock
            ]
        );
    }

    protected function tearDown(): void
    {
        $this->block = null;
    }

    public function testGetIdentities()
    {
        $productTags = ['catalog_product_1'];
        $product = $this->createMock(\Magento\Catalog\Model\Product::class);
        $product->expects($this->once())->method('getIdentities')->willReturn($productTags);
        $this->registryMock->expects($this->once())
            ->method('registry')
            ->with('current_product')
            ->willReturn($product);
        $this->assertEquals($productTags, $this->block->getIdentities());
    }

    /**
     * @param int $productStockQty
     * @param int|null $productId
     * @param int|null $websiteId
     * @param int|null $dataQty
     * @param int $expectedQty
     * @dataProvider getStockQtyDataProvider
     */
    public function testGetStockQty($productStockQty, $productId, $websiteId, $dataQty, $expectedQty)
    {
        $this->assertNull($this->block->getData('product_stock_qty'));
        if ($dataQty) {
            $this->setDataArrayValue('product_stock_qty', $dataQty);
        } else {
            $product = $this->createPartialMock(
                \Magento\Catalog\Model\Product::class,
                ['getId', 'getStore', '__wakeup']
            );
            $product->expects($this->any())->method('getId')->willReturn($productId);
            $store = $this->createPartialMock(\Magento\Store\Model\Store::class, ['getWebsiteId', '__wakeup']);
            $store->expects($this->any())->method('getWebsiteId')->willReturn($websiteId);
            $product->expects($this->any())->method('getStore')->willReturn($store);

            $this->registryMock->expects($this->any())
                ->method('registry')
                ->with('current_product')
                ->willReturn($product);

            if ($productId) {
                $stockStatus = $this->getMockBuilder(\Magento\CatalogInventory\Api\Data\StockStatusInterface::class)
                    ->getMockForAbstractClass();
                $stockStatus->expects($this->any())->method('getQty')->willReturn($productStockQty);
                $this->stockRegistryMock->expects($this->once())
                    ->method('getStockStatus')
                    ->with($this->equalTo($productId), $this->equalTo($websiteId))
                    ->willReturn($stockStatus);
            }
        }
        $this->assertSame($expectedQty, $this->block->getStockQty());
        $this->assertSame($expectedQty, $this->block->getData('product_stock_qty'));
    }

    public function te1stGetStockQtyLeft()
    {
        $productId = 1;
        $minQty = 0;
        $websiteId = 1;
        $stockQty = 2;

        $storeMock = $this->getMockBuilder(\Magento\Store\Model\Store::class)
            ->disableOriginalConstructor()
            ->getMock();
        $storeMock->expects($this->once())
            ->method('getWebsiteId')
            ->willReturn($websiteId);
        $product = $this->createMock(\Magento\Catalog\Model\Product::class);
        $product->expects($this->any())
            ->method('getId')
            ->willReturn($productId);
        $product->expects($this->once())
            ->method('getStore')
            ->willReturn($storeMock);
        $this->registryMock->expects($this->once())
            ->method('registry')
            ->with('current_product')
            ->willReturn($product);

        $stockItemMock = $this->getMockBuilder(\Magento\CatalogInventory\Api\Data\StockItemInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $stockItemMock->expects($this->once())
            ->method('getMinQty')
            ->willReturn($minQty);
        $this->stockRegistryMock->expects($this->once())
            ->method('getStockItem')
            ->with($productId)
            ->willReturn($stockItemMock);

        $this->assertEquals($stockQty, $this->block->getStockQtyLeft());
    }

    /**
     * @return array
     */
    public function getStockQtyDataProvider()
    {
        return [
            [
                'product qty' => 100,
                'product id' => 5,
                'website id' => 0,
                'default qty' => null,
                'expected qty' => 100,
            ],
            [
                'product qty' => 100,
                'product id' => null,
                'website id' => null,
                'default qty' => null,
                'expected qty' => 0
            ],
            [
                'product qty' => null,
                'product id' => null,
                'website id' => null,
                'default qty' => 50,
                'expected qty' => 50
            ],
        ];
    }

    /**
     * @param string $key
     * @param string|float|int $value
     */
    protected function setDataArrayValue($key, $value)
    {
        $property = new \ReflectionProperty($this->block, '_data');
        $property->setAccessible(true);
        $dataArray = $property->getValue($this->block);
        $dataArray[$key] = $value;
        $property->setValue($this->block, $dataArray);
    }

    public function testGetThresholdQty()
    {
        $this->scopeConfigMock->expects($this->once())->method('getValue')->willReturn(5);
        $this->assertEquals(5, $this->block->getThresholdQty());
    }
}
