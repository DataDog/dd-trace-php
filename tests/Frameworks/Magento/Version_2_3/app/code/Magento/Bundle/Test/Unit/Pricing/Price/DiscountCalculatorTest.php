<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Bundle\Test\Unit\Pricing\Price;

/**
 * Class DiscountCalculatorTest
 */
class DiscountCalculatorTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Bundle\Pricing\Price\DiscountCalculator
     */
    protected $calculator;

    /**
     * @var \Magento\Catalog\Model\Product|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $productMock;

    /**
     * @var \Magento\Framework\Pricing\PriceInfo\Base |\PHPUnit\Framework\MockObject\MockObject
     */
    protected $priceInfoMock;

    /**
     * @var \Magento\Catalog\Pricing\Price\FinalPrice|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $finalPriceMock;

    /**
     * @var \Magento\Bundle\Pricing\Price\DiscountProviderInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $priceMock;

    /**
     * Test setUp
     */
    protected function setUp(): void
    {
        $this->productMock = $this->createMock(\Magento\Catalog\Model\Product::class);
        $this->priceInfoMock = $this->createPartialMock(
            \Magento\Framework\Pricing\PriceInfo\Base::class,
            ['getPrice', 'getPrices']
        );
        $this->finalPriceMock = $this->createMock(\Magento\Catalog\Pricing\Price\FinalPrice::class);
        $this->priceMock = $this->getMockForAbstractClass(
            \Magento\Bundle\Pricing\Price\DiscountProviderInterface::class
        );
        $this->calculator = new \Magento\Bundle\Pricing\Price\DiscountCalculator();
    }

    /**
     * Returns price mock with specified %
     *
     * @param int $value
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    protected function getPriceMock($value)
    {
        $price = clone $this->priceMock;
        $price->expects($this->exactly(3))
            ->method('getDiscountPercent')
            ->willReturn($value);
        return $price;
    }

    /**
     * test method calculateDiscount with default price amount
     */
    public function testCalculateDiscountWithDefaultAmount()
    {
        $this->productMock->expects($this->exactly(2))
            ->method('getPriceInfo')
            ->willReturn($this->priceInfoMock);
        $this->priceInfoMock->expects($this->once())
            ->method('getPrice')
            ->with($this->equalTo(\Magento\Catalog\Pricing\Price\FinalPrice::PRICE_CODE))
            ->willReturn($this->finalPriceMock);
        $this->finalPriceMock->expects($this->once())
            ->method('getValue')
            ->willReturn(100);
        $this->priceInfoMock->expects($this->once())
            ->method('getPrices')
            ->willReturn(
                
                    [
                        $this->getPriceMock(30),
                        $this->getPriceMock(20),
                        $this->getPriceMock(40),
                    ]
                
            );
        $this->assertEquals(20, $this->calculator->calculateDiscount($this->productMock));
    }

    /**
     * test method calculateDiscount with custom price amount
     */
    public function testCalculateDiscountWithCustomAmount()
    {
        $this->productMock->expects($this->once())
            ->method('getPriceInfo')
            ->willReturn($this->priceInfoMock);
        $this->priceInfoMock->expects($this->once())
            ->method('getPrices')
            ->willReturn(
                
                    [
                        $this->getPriceMock(30),
                        $this->getPriceMock(20),
                        $this->getPriceMock(40),
                    ]
                
            );
        $this->assertEquals(10, $this->calculator->calculateDiscount($this->productMock, 50));
    }
}
