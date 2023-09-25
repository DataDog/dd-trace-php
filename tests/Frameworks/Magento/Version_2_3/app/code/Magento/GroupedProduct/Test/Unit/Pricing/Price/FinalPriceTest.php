<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\GroupedProduct\Test\Unit\Pricing\Price;

/**
 * Class FinalPriceTest
 */
class FinalPriceTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\GroupedProduct\Pricing\Price\FinalPrice
     */
    protected $finalPrice;

    /**
     * @var \Magento\Catalog\Model\Product|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $saleableItemMock;

    /**
     * @var \Magento\Framework\Pricing\Adjustment\Calculator|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $calculatorMock;

    /**
     * @var \Magento\Framework\Pricing\PriceCurrencyInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $priceCurrencyMock;

    /**
     * Setup
     */
    protected function setUp(): void
    {
        $this->saleableItemMock =  $this->createMock(\Magento\Catalog\Model\Product::class);
        $this->calculatorMock = $this->createMock(\Magento\Framework\Pricing\Adjustment\Calculator::class);

        $this->priceCurrencyMock = $this->createMock(\Magento\Framework\Pricing\PriceCurrencyInterface::class);

        $this->finalPrice = new \Magento\GroupedProduct\Pricing\Price\FinalPrice(
            $this->saleableItemMock,
            null,
            $this->calculatorMock,
            $this->priceCurrencyMock
        );
    }

    public function testGetMinProduct()
    {
        $product1 = $this->getProductMock(10);
        $product2 = $this->getProductMock(20);

        $typeInstanceMock = $this->createMock(\Magento\GroupedProduct\Model\Product\Type\Grouped::class);
        $typeInstanceMock->expects($this->once())
            ->method('getAssociatedProducts')
            ->with($this->equalTo($this->saleableItemMock))
            ->willReturn([$product1, $product2]);

        $this->saleableItemMock->expects($this->once())
            ->method('getTypeInstance')
            ->willReturn($typeInstanceMock);

        $this->assertEquals($product1, $this->finalPrice->getMinProduct());
    }

    public function testGetValue()
    {
        $product1 = $this->getProductMock(10);
        $product2 = $this->getProductMock(20);

        $typeInstanceMock = $this->createMock(\Magento\GroupedProduct\Model\Product\Type\Grouped::class);
        $typeInstanceMock->expects($this->once())
            ->method('getAssociatedProducts')
            ->with($this->equalTo($this->saleableItemMock))
            ->willReturn([$product1, $product2]);

        $this->saleableItemMock->expects($this->once())
            ->method('getTypeInstance')
            ->willReturn($typeInstanceMock);

        $this->assertEquals(10, $this->finalPrice->getValue());
    }

    public function testGetValueWithoutMinProduct()
    {
        $typeInstanceMock = $this->createMock(
            \Magento\GroupedProduct\Model\Product\Type\Grouped::class
        );
        $typeInstanceMock->expects($this->once())
            ->method('getAssociatedProducts')
            ->with($this->equalTo($this->saleableItemMock))
            ->willReturn([]);

        $this->saleableItemMock->expects($this->once())
            ->method('getTypeInstance')
            ->willReturn($typeInstanceMock);

        $this->assertEquals(0.00, $this->finalPrice->getValue());
    }

    /**
     * @param $price
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    protected function getProductMock($price)
    {
        $priceTypeMock = $this->createMock(\Magento\Catalog\Pricing\Price\FinalPrice::class);
        $priceTypeMock->expects($this->any())
            ->method('getValue')
            ->willReturn($price);

        $priceInfoMock = $this->createMock(\Magento\Framework\Pricing\PriceInfo\Base::class);
        $priceInfoMock->expects($this->any())
            ->method('getPrice')
            ->with($this->equalTo(\Magento\Catalog\Pricing\Price\FinalPrice::PRICE_CODE))
            ->willReturn($priceTypeMock);

        $productMock = $this->createMock(\Magento\Catalog\Model\Product::class);
        $productMock->expects($this->any())
            ->method('setQty')
            ->with($this->equalTo(\Magento\Framework\Pricing\PriceInfoInterface::PRODUCT_QUANTITY_DEFAULT));
        $productMock->expects($this->any())
            ->method('getPriceInfo')
            ->willReturn($priceInfoMock);

        return $productMock;
    }
}
