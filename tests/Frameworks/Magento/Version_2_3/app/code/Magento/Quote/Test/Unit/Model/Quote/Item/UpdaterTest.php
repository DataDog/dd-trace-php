<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Quote\Test\Unit\Model\Quote\Item;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;

/**
 * Tests  for Magento\Quote\Model\Service\Quote\Updater
 *
 */
class UpdaterTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Quote\Model\Quote\Item\Updater |\PHPUnit\Framework\MockObject\MockObject
     */
    protected $object;

    /**
     * @var \Magento\Quote\Model\Quote\Item |\PHPUnit\Framework\MockObject\MockObject
     */
    protected $itemMock;

    /**
     * @var \Magento\CatalogInventory\Model\Stock\Item |\PHPUnit\Framework\MockObject\MockObject
     */
    protected $stockItemMock;

    /**
     * @var \Magento\Framework\Locale\Format |\PHPUnit\Framework\MockObject\MockObject
     */
    protected $localeFormat;

    /**
     * @var \Magento\Catalog\Model\Product |\PHPUnit\Framework\MockObject\MockObject
     */
    protected $productMock;

    /**
     * @var \Magento\Framework\Serialize\Serializer\Json
     */
    private $serializer;

    protected function setUp(): void
    {
        $this->productMock = $this->createPartialMock(\Magento\Catalog\Model\Product::class, [
                'getStockItem',
                '__wakeup',
                'setIsSuperMode',
                'unsSkipCheckRequiredOption'
            ]);

        $this->localeFormat = $this->createPartialMock(\Magento\Framework\Locale\Format::class, [
                'getNumber', 'getPriceFormat'
            ]);

        $this->itemMock = $this->createPartialMock(\Magento\Quote\Model\Quote\Item::class, [
                'updateItem',
                'getProduct',
                'setQty',
                'setNoDiscount',
                'checkData',
                '__wakeup',
                'getBuyRequest',
                'addOption',
                'setCustomPrice',
                'setOriginalCustomPrice',
                'setData',
                'hasData',
                'setIsQtyDecimal'
            ]);

        $this->stockItemMock = $this->createPartialMock(\Magento\CatalogInventory\Model\Stock\Item::class, [
                'getIsQtyDecimal',
                '__wakeup'
            ]);
        $this->serializer = $this->getMockBuilder(\Magento\Framework\Serialize\Serializer\Json::class)
            ->setMethods(['serialize'])
            ->getMockForAbstractClass();

        $this->object = (new ObjectManager($this))
            ->getObject(
                \Magento\Quote\Model\Quote\Item\Updater::class,
                [
                    'localeFormat' => $this->localeFormat,
                    'serializer' => $this->serializer
                ]
            );
    }

    /**
     */
    public function testUpdateNoQty()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The qty value is required to update quote item.');

        $this->object->update($this->itemMock, []);
    }

    /**
     * @dataProvider qtyProvider
     */
    public function testUpdateNotQtyDecimal($qty, $expectedQty)
    {
        $this->itemMock->expects($this->any())
            ->method('setNoDiscount')
            ->willReturn(true);

        $this->itemMock->expects($this->any())
            ->method('setQty')
            ->with($this->equalTo($expectedQty));

        $this->productMock->expects($this->any())
            ->method('getStockItem')
            ->willReturn($this->stockItemMock);

        $this->productMock->expects($this->any())
            ->method('setIsSuperMode')
            ->with($this->equalTo(true));
        $this->productMock->expects($this->any())
            ->method('unsSkipCheckRequiredOption');

        $this->itemMock->expects($this->any())
            ->method('getProduct')
            ->willReturn($this->productMock);

        $result = $this->object->update($this->itemMock, ['qty' => $qty]);
        $this->assertEquals($result, $this->object);
    }

    /**
     * @return array
     */
    public function qtyProvider()
    {
        return [
            [1, 1],
            [5.66, 5],
            ['test', 1],
            [-3, 1],
            [0, 1],
            [-2.99, 1]
        ];
    }

    /**
     * @return array
     */
    public function qtyProviderDecimal()
    {
        return [
            [1, 1],
            [5.66, 5.66],
            ['test', 1],
            [-3, 1],
            [0, 1],
            [-2.99, 1]
        ];
    }

    /**
     * @dataProvider qtyProviderDecimal
     */
    public function testUpdateQtyDecimal($qty, $expectedQty)
    {
        $this->itemMock->expects($this->any())
            ->method('setNoDiscount')
            ->willReturn(true);

        $this->itemMock->expects($this->any())
            ->method('setQty')
            ->with($this->equalTo($expectedQty));

        $this->itemMock->expects($this->any())
            ->method('setIsQtyDecimal')
            ->willReturn(true);

        $this->stockItemMock->expects($this->any())
            ->method('getIsQtyDecimal')
            ->willReturn(true);

        $this->productMock->expects($this->any())
            ->method('getStockItem')
            ->willReturn($this->stockItemMock);

        $this->productMock->expects($this->any())
            ->method('setIsSuperMode')
            ->with($this->equalTo(true));
        $this->productMock->expects($this->any())
            ->method('unsSkipCheckRequiredOption');

        $this->itemMock->expects($this->any())
            ->method('getProduct')
            ->willReturn($this->productMock);

        $object = $this->object->update($this->itemMock, ['qty' => $qty]);
        $this->assertEquals($this->object, $object);
    }

    public function testUpdateQtyDecimalWithConfiguredOption()
    {
        $this->itemMock->expects($this->any())
            ->method('setIsQtyDecimal')
            ->with($this->equalTo(1));

        $this->stockItemMock->expects($this->any())
            ->method('getIsQtyDecimal')
            ->willReturn(true);

        $this->productMock->expects($this->any())
            ->method('getStockItem')
            ->willReturn($this->stockItemMock);

        $this->itemMock->expects($this->any())
            ->method('getProduct')
            ->willReturn($this->productMock);

        $object = $this->object->update($this->itemMock, ['qty' => 3, 'use_discount' => true]);
        $this->assertEquals($this->object, $object);
    }

    /**
     * @covers \Magento\Quote\Model\Quote\Item\Updater::setCustomPrice()
     */
    public function testUpdateCustomPrice()
    {
        $customPrice = 9.99;
        $qty = 3;
        $buyRequestMock = $this->createPartialMock(\Magento\Framework\DataObject::class, [
                'setCustomPrice',
                'setValue',
                'setCode',
                'setProduct',
                'getData'
            ]);
        $buyRequestMock->expects($this->any())
            ->method('setCustomPrice')
            ->with($this->equalTo($customPrice));
        $buyRequestMock->expects($this->any())
            ->method('getData')
            ->willReturn(['custom_price' => $customPrice]);
        $this->serializer->expects($this->any())
            ->method('serialize')
            ->willReturn(json_encode($buyRequestMock->getData()));
        $buyRequestMock->expects($this->any())
            ->method('setValue')
            ->with($this->equalTo('{"custom_price":' . $customPrice . '}'));
        $buyRequestMock->expects($this->any())
            ->method('setCode')
            ->with($this->equalTo('info_buyRequest'));

        $buyRequestMock->expects($this->any())
            ->method('setProduct')
            ->with($this->equalTo($this->productMock));

        $this->itemMock->expects($this->any())
            ->method('setIsQtyDecimal')
            ->with($this->equalTo(1));
        $this->itemMock->expects($this->any())
            ->method('getBuyRequest')
            ->willReturn($buyRequestMock);

        $this->stockItemMock->expects($this->any())
            ->method('getIsQtyDecimal')
            ->willReturn(true);

        $this->productMock->expects($this->any())
            ->method('getStockItem')
            ->willReturn($this->stockItemMock);

        $this->itemMock->expects($this->any())
            ->method('getProduct')
            ->willReturn($this->productMock);
        $this->itemMock->expects($this->any())
            ->method('addOption')
            ->willReturn($buyRequestMock);
        $this->itemMock->expects($this->any())
            ->method('setQty')
            ->with($this->equalTo($qty));

        $this->localeFormat->expects($this->any())
            ->method('getNumber')
            ->willReturnArgument(0);

        $object = $this->object->update($this->itemMock, ['qty' => $qty, 'custom_price' => $customPrice]);
        $this->assertEquals($this->object, $object);
    }

    /**
     * @covers \Magento\Quote\Model\Quote\Item\Updater::unsetCustomPrice()
     */
    public function testUpdateUnsetCustomPrice()
    {
        $qty = 3;
        $buyRequestMock = $this->createPartialMock(\Magento\Framework\DataObject::class, [
                'setCustomPrice',
                'setValue',
                'setCode',
                'setProduct',
                'getData',
                'unsetData',
                'hasData',
            ]);
        $buyRequestMock->expects($this->never())->method('setCustomPrice');
        $buyRequestMock->expects($this->once())->method('getData')->willReturn([]);
        $serializer = $this->getMockBuilder(\Magento\Framework\Serialize\Serializer\Json::class)
            ->setMethods(['serialize'])
            ->getMockForAbstractClass();
        $serializer->expects($this->any())
            ->method('serialize')
            ->willReturn('{}');
        $objectManagerHelper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $objectManagerHelper->setBackwardCompatibleProperty($this->object, 'serializer', $serializer);
        $buyRequestMock->expects($this->once())->method('unsetData')->with($this->equalTo('custom_price'));
        $buyRequestMock->expects($this->once())
            ->method('hasData')
            ->with($this->equalTo('custom_price'))
            ->willReturn(true);

        $buyRequestMock->expects($this->any())
            ->method('setValue')
            ->with($this->equalTo('{}'));
        $buyRequestMock->expects($this->any())
            ->method('setCode')
            ->with($this->equalTo('info_buyRequest'));

        $buyRequestMock->expects($this->any())
            ->method('setProduct')
            ->with($this->equalTo($this->productMock));

        $this->itemMock->expects($this->any())
            ->method('setIsQtyDecimal')
            ->with($this->equalTo(1));
        $this->itemMock->expects($this->any())
            ->method('getBuyRequest')
            ->willReturn($buyRequestMock);

        $this->stockItemMock->expects($this->any())
            ->method('getIsQtyDecimal')
            ->willReturn(true);

        $this->productMock->expects($this->any())
            ->method('getStockItem')
            ->willReturn($this->stockItemMock);

        $this->itemMock->expects($this->any())
            ->method('getProduct')
            ->willReturn($this->productMock);
        $this->itemMock->expects($this->any())
            ->method('addOption')
            ->willReturn($buyRequestMock);

        $this->itemMock->expects($this->exactly(2))
            ->method('setData')
            ->withConsecutive(
                ['custom_price', null],
                ['original_custom_price', null]
            );

        $this->itemMock->expects($this->once())
            ->method('hasData')
            ->with($this->equalTo('custom_price'))
            ->willReturn(true);

        $this->itemMock->expects($this->never())->method('setCustomPrice');
        $this->itemMock->expects($this->never())->method('setOriginalCustomPrice');

        $this->localeFormat->expects($this->any())
            ->method('getNumber')
            ->willReturnArgument(0);

        $object = $this->object->update($this->itemMock, ['qty' => $qty]);
        $this->assertEquals($this->object, $object);
    }
}
