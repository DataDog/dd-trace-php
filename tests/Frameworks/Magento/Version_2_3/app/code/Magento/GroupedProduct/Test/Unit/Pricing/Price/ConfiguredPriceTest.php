<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\GroupedProduct\Test\Unit\Pricing\Price;

use Magento\Catalog\Pricing\Price\BasePrice;
use Magento\GroupedProduct\Pricing\Price\ConfiguredPrice;
use Magento\GroupedProduct\Pricing\Price\FinalPrice;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ConfiguredPriceTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var ConfiguredPrice
     */
    protected $model;

    /**
     * @var \Magento\Framework\Pricing\SaleableInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $saleableItem;

    /**
     * @var \Magento\Framework\Pricing\Adjustment\CalculatorInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $calculator;

    /**
     * @var \Magento\Framework\Pricing\PriceCurrencyInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $priceCurrency;

    /**
     * @var \Magento\Framework\Pricing\Price\PriceInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $price;

    /**
     * @var \Magento\Framework\Pricing\PriceInfoInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $priceInfo;

    protected function setUp(): void
    {
        $this->price = $this->getMockBuilder(\Magento\Framework\Pricing\Price\PriceInterface::class)
            ->getMock();

        $this->priceInfo = $this->getMockBuilder(\Magento\Framework\Pricing\PriceInfoInterface::class)
            ->getMock();

        $this->saleableItem = $this->getMockBuilder(\Magento\Framework\Pricing\SaleableInterface::class)
            ->setMethods([
                'getTypeId',
                'getId',
                'getQty',
                'getPriceInfo',
                'getTypeInstance',
                'getStore',
                'getCustomOption',
                'hasFinalPrice'
            ])
            ->getMock();
        $this->saleableItem->expects($this->once())
            ->method('getPriceInfo')
            ->willReturn($this->priceInfo);

        $this->calculator = $this->getMockBuilder(\Magento\Framework\Pricing\Adjustment\CalculatorInterface::class)
            ->getMock();

        $this->priceCurrency = $this->getMockBuilder(\Magento\Framework\Pricing\PriceCurrencyInterface::class)
            ->getMock();

        $this->model = new ConfiguredPrice(
            $this->saleableItem,
            null,
            $this->calculator,
            $this->priceCurrency
        );
    }

    public function testSetItem()
    {
        $item = $this->getMockBuilder(\Magento\Catalog\Model\Product\Configuration\Item\ItemInterface::class)
            ->getMock();

        $this->model->setItem($item);
    }

    public function testGetValueNoItem()
    {
        $resultPrice = rand(1, 9);

        $this->price->expects($this->once())
            ->method('getValue')
            ->willReturn($resultPrice);

        $this->priceInfo->expects($this->once())
            ->method('getPrice')
            ->with(BasePrice::PRICE_CODE)
            ->willReturn($this->price);

        $this->assertEquals($resultPrice, $this->model->getValue());
    }

    public function testGetValue()
    {
        $resultPrice = rand(1, 9);
        $customOptionOneQty = rand(1, 9);
        $customOptionTwoQty = rand(1, 9);

        $priceInfoBase = $this->getMockBuilder(\Magento\Framework\Pricing\PriceInfo\Base::class)
            ->disableOriginalConstructor()
            ->getMock();
        $priceInfoBase->expects($this->any())
            ->method('getPrice')
            ->with(FinalPrice::PRICE_CODE)
            ->willReturn($this->price);

        $productOne = $this->getMockBuilder(\Magento\Catalog\Model\Product::class)
            ->disableOriginalConstructor()
            ->getMock();
        $productOne->expects($this->once())
            ->method('getId')
            ->willReturn(1);
        $productOne->expects($this->once())
            ->method('getPriceInfo')
            ->willReturn($priceInfoBase);

        $productTwo = $this->getMockBuilder(\Magento\Catalog\Model\Product::class)
            ->disableOriginalConstructor()
            ->getMock();
        $productTwo->expects($this->once())
            ->method('getId')
            ->willReturn(2);
        $productTwo->expects($this->once())
            ->method('getPriceInfo')
            ->willReturn($priceInfoBase);

        $this->price->expects($this->any())
            ->method('getValue')
            ->willReturn($resultPrice);

        $customOptionOne = $this->getMockBuilder(\Magento\Wishlist\Model\Item\Option::class)
            ->disableOriginalConstructor()
            ->getMock();
        $customOptionOne->expects($this->any())
            ->method('getValue')
            ->willReturn($customOptionOneQty);

        $customOptionTwo = $this->getMockBuilder(\Magento\Wishlist\Model\Item\Option::class)
            ->disableOriginalConstructor()
            ->getMock();
        $customOptionTwo->expects($this->any())
            ->method('getValue')
            ->willReturn($customOptionTwoQty);

        $store = $this->getMockBuilder(\Magento\Store\Model\Store::class)
            ->disableOriginalConstructor()
            ->getMock();

        $groupedProduct = $this->getMockBuilder(\Magento\GroupedProduct\Model\Product\Type\Grouped::class)
            ->disableOriginalConstructor()
            ->getMock();
        $groupedProduct->expects($this->once())
            ->method('setStoreFilter')
            ->with($store, $this->saleableItem)
            ->willReturnSelf();
        $groupedProduct->expects($this->once())
            ->method('getAssociatedProducts')
            ->with($this->saleableItem)
            ->willReturn([$productOne, $productTwo]);

        $this->saleableItem->expects($this->once())
            ->method('getTypeInstance')
            ->willReturn($groupedProduct);
        $this->saleableItem->expects($this->any())
            ->method('getStore')
            ->willReturn($store);
        $this->saleableItem->expects($this->any())
            ->method('getCustomOption')
            ->willReturnMap([
                ['associated_product_' . 1, $customOptionOne],
                ['associated_product_' . 2, $customOptionTwo],
            ]);

        $item = $this->getMockBuilder(\Magento\Catalog\Model\Product\Configuration\Item\ItemInterface::class)
            ->getMock();

        $this->model->setItem($item);

        $result = 0;
        foreach ([$customOptionOneQty, $customOptionTwoQty] as $qty) {
            $result += $resultPrice * $qty;
        }

        $this->assertEquals($result, $this->model->getValue());
    }

    public function testGetAmount()
    {
        $resultPrice = rand(1, 9);

        $this->price->expects($this->exactly(4))
            ->method('getValue')
            ->willReturn($resultPrice);

        $this->priceInfo->expects($this->once())
            ->method('getPrice')
            ->with(BasePrice::PRICE_CODE)
            ->willReturn($this->price);

        $this->calculator->expects($this->once())
            ->method('getAmount')
            ->with($resultPrice, $this->saleableItem)
            ->willReturn($resultPrice);

        $this->assertEquals($resultPrice, $this->model->getAmount());
    }
}
