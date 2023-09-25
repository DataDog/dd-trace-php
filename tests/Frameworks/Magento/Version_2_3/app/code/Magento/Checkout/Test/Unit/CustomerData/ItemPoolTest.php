<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Checkout\Test\Unit\CustomerData;

class ItemPoolTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $objectManagerMock;

    /**
     * @var string
     */
    protected $defaultItemId = 'default_item_id';

    /**
     * @var string[]
     */
    protected $itemMap = [];

    /**
     * @var \Magento\Checkout\CustomerData\ItemPool
     */
    protected $model;

    protected function setUp(): void
    {
        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);

        $this->objectManagerMock = $this->createMock(\Magento\Framework\ObjectManagerInterface::class);
        $this->model = $objectManager->getObject(
            \Magento\Checkout\CustomerData\ItemPool::class,
            [
                'objectManager' => $this->objectManagerMock,
                'defaultItemId' => $this->defaultItemId,
                'itemMap' => $this->itemMap,
            ]
        );
    }

    public function testGetItemDataIfItemNotExistInMap()
    {
        $itemData = ['key' => 'value'];
        $productType = 'product_type';
        $quoteItemMock = $this->createMock(\Magento\Quote\Model\Quote\Item::class);
        $quoteItemMock->expects($this->once())->method('getProductType')->willReturn($productType);

        $itemMock = $this->createMock(\Magento\Checkout\CustomerData\ItemInterface::class);
        $itemMock->expects($this->once())->method('getItemData')->with($quoteItemMock)->willReturn($itemData);

        $this->objectManagerMock->expects($this->once())
            ->method('get')
            ->with($this->defaultItemId)
            ->willReturn($itemMock);

        $this->assertEquals($itemData, $this->model->getItemData($quoteItemMock));
    }

    public function testGetItemDataIfItemExistInMap()
    {
        $itemData = ['key' => 'value'];
        $productType = 'product_type';
        $this->itemMap[$productType] = 'product_id';

        $quoteItemMock = $this->createMock(\Magento\Quote\Model\Quote\Item::class);
        $quoteItemMock->expects($this->once())->method('getProductType')->willReturn($productType);

        $itemMock = $this->createMock(\Magento\Checkout\CustomerData\ItemInterface::class);
        $itemMock->expects($this->once())->method('getItemData')->with($quoteItemMock)->willReturn($itemData);

        $this->objectManagerMock->expects($this->once())
            ->method('get')
            ->with($this->itemMap[$productType])
            ->willReturn($itemMock);

        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->model = $objectManager->getObject(
            \Magento\Checkout\CustomerData\ItemPool::class,
            [
                'objectManager' => $this->objectManagerMock,
                'defaultItemId' => $this->defaultItemId,
                'itemMap' => $this->itemMap,
            ]
        );

        $this->assertEquals($itemData, $this->model->getItemData($quoteItemMock));
    }

    public function testGetItemDataIfItemNotValid()
    {
        $this->expectException('Magento\Framework\Exception\LocalizedException');
        $this->expectExceptionMessage('product_type doesn\'t extend \Magento\Checkout\CustomerData\ItemInterface');
        $itemData = ['key' => 'value'];
        $productType = 'product_type';
        $quoteItemMock = $this->createMock(\Magento\Quote\Model\Quote\Item::class);
        $quoteItemMock->expects($this->once())->method('getProductType')->willReturn($productType);
        $this->objectManagerMock->expects($this->once())
            ->method('get')
            ->with($this->defaultItemId)
            ->willReturn($this->createMock(\Magento\Quote\Model\Quote\Item::class));
        $this->assertEquals($itemData, $this->model->getItemData($quoteItemMock));
    }
}
