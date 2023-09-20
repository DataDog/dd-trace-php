<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Bundle\Test\Unit\Pricing\Price;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use Magento\Catalog\Pricing\Price\CustomOptionPrice;
use Magento\Bundle\Model\Product\Price;

class BundleRegularPriceTest extends \PHPUnit\Framework\TestCase
{
    /** @var \Magento\Bundle\Pricing\Price\BundleRegularPrice */
    protected $regularPrice;

    /** @var ObjectManagerHelper */
    protected $objectManagerHelper;

    /** @var \Magento\Framework\Pricing\SaleableInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $saleableInterfaceMock;

    /** @var \Magento\Bundle\Pricing\Adjustment\BundleCalculatorInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $bundleCalculatorMock;

    /** @var \Magento\Framework\Pricing\PriceInfo\Base |\PHPUnit\Framework\MockObject\MockObject */
    protected $priceInfoMock;

    /** @var CustomOptionPrice|\PHPUnit\Framework\MockObject\MockObject */
    protected $customOptionPriceMock;

    /**
     * @var int
     */
    protected $quantity = 1;

    /**
     * @var \Magento\Framework\Pricing\PriceCurrencyInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $priceCurrencyMock;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->saleableInterfaceMock = $this->getMockBuilder(\Magento\Catalog\Model\Product::class)
            ->disableOriginalConstructor()
            ->setMethods(['getPriceInfo', 'getPriceType', 'getPrice'])
            ->getMock();
        $this->bundleCalculatorMock = $this->createMock(
            \Magento\Bundle\Pricing\Adjustment\BundleCalculatorInterface::class
        );

        $this->priceInfoMock = $this->createMock(\Magento\Framework\Pricing\PriceInfo\Base::class);

        $this->customOptionPriceMock = $this->getMockBuilder(\Magento\Catalog\Pricing\Price\CustomOptionPrice::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->saleableInterfaceMock->expects($this->once())
            ->method('getPriceInfo')
            ->willReturn($this->priceInfoMock);

        $this->priceCurrencyMock = $this->createMock(\Magento\Framework\Pricing\PriceCurrencyInterface::class);

        $this->objectManagerHelper = new ObjectManagerHelper($this);
        $this->regularPrice = new \Magento\Bundle\Pricing\Price\BundleRegularPrice(
            $this->saleableInterfaceMock,
            $this->quantity,
            $this->bundleCalculatorMock,
            $this->priceCurrencyMock
        );
    }

    public function testGetAmount()
    {
        $expectedResult = 5;

        $this->saleableInterfaceMock->expects($this->once())
            ->method('getPrice')
            ->willReturn($expectedResult);

        $this->bundleCalculatorMock->expects($this->once())
            ->method('getMinRegularAmount')
            ->with($expectedResult, $this->saleableInterfaceMock)
            ->willReturn($expectedResult);

        $this->priceCurrencyMock->expects($this->once())
            ->method('convertAndRound')
            ->willReturnArgument(0);

        $result = $this->regularPrice->getAmount();
        $this->assertEquals($expectedResult, $result, 'Incorrect amount');

        //Calling a second time, should use cached value
        $result = $this->regularPrice->getAmount();
        $this->assertEquals($expectedResult, $result, 'Incorrect amount');
    }

    public function testGetMaximalPrice()
    {
        $expectedResult = 5;

        $this->saleableInterfaceMock->expects($this->once())
            ->method('getPrice')
            ->willReturn($expectedResult);

        $this->bundleCalculatorMock->expects($this->once())
            ->method('getMaxRegularAmount')
            ->with($expectedResult, $this->saleableInterfaceMock)
            ->willReturn($expectedResult);

        $this->priceCurrencyMock->expects($this->once())
            ->method('convertAndRound')
            ->willReturnArgument(0);

        $result = $this->regularPrice->getMaximalPrice();
        $this->assertEquals($expectedResult, $result, 'Incorrect amount');

        //Calling a second time, should use cached value
        $result = $this->regularPrice->getMaximalPrice();
        $this->assertEquals($expectedResult, $result, 'Incorrect amount the second time');
    }

    public function testGetMaximalPriceForFixedPriceBundleWithOption()
    {
        $price = 5;
        $maxOptionPrice = 2;

        $expectedPrice = $price + $maxOptionPrice;

        $this->priceInfoMock->expects($this->atLeastOnce())
            ->method('getPrice')
            ->with(CustomOptionPrice::PRICE_CODE)
            ->willReturn($this->customOptionPriceMock);

        $this->customOptionPriceMock->expects($this->once())
            ->method('getCustomOptionRange')
            ->with(false)
            ->willReturn($maxOptionPrice);

        $this->saleableInterfaceMock->expects($this->once())
            ->method('getPriceType')
            ->willReturn(Price::PRICE_TYPE_FIXED);

        $this->saleableInterfaceMock->expects($this->once())
            ->method('getPrice')
            ->willReturn($price);

        $this->bundleCalculatorMock->expects($this->once())
            ->method('getMaxRegularAmount')
            ->with($expectedPrice, $this->saleableInterfaceMock)
            ->willReturn($expectedPrice);

        $this->priceCurrencyMock->expects($this->once())
            ->method('convertAndRound')
            ->willReturnArgument(0);

        $result = $this->regularPrice->getMaximalPrice();
        $this->assertEquals($expectedPrice, $result, 'Incorrect amount');

        //Calling a second time, should use cached value
        $result = $this->regularPrice->getMaximalPrice();
        $this->assertEquals($expectedPrice, $result, 'Incorrect amount the second time');
    }

    public function testGetMinimalPrice()
    {
        $expectedResult = 5;

        $this->saleableInterfaceMock->expects($this->once())
            ->method('getPrice')
            ->willReturn($expectedResult);

        $this->priceCurrencyMock->expects($this->once())
            ->method('convertAndRound')
            ->willReturnArgument(0);

        $this->bundleCalculatorMock->expects($this->once())
            ->method('getMinRegularAmount')
            ->with($expectedResult, $this->saleableInterfaceMock)
            ->willReturn($expectedResult);

        $result = $this->regularPrice->getMinimalPrice();
        $this->assertEquals($expectedResult, $result, 'Incorrect amount');

        //Calling a second time, should use cached value
        $result = $this->regularPrice->getMinimalPrice();
        $this->assertEquals($expectedResult, $result, 'Incorrect amount the second time');
    }

    public function testGetMinimalPriceForFixedPricedBundleWithOptions()
    {
        $price = 5;
        $minOptionPrice = 1;
        $expectedValue = $price + $minOptionPrice;

        $this->saleableInterfaceMock->expects($this->once())
            ->method('getPrice')
            ->willReturn($price);

        $this->saleableInterfaceMock->expects($this->once())
            ->method('getPriceType')
            ->willReturn(Price::PRICE_TYPE_FIXED);

        $this->priceInfoMock->expects($this->atLeastOnce())
            ->method('getPrice')
            ->with(CustomOptionPrice::PRICE_CODE)
            ->willReturn($this->customOptionPriceMock);

        $this->customOptionPriceMock->expects($this->once())
            ->method('getCustomOptionRange')
            ->with(true)
            ->willReturn($minOptionPrice);

        $this->priceCurrencyMock->expects($this->once())
            ->method('convertAndRound')
            ->willReturnArgument(0);

        $this->bundleCalculatorMock->expects($this->once())
            ->method('getMinRegularAmount')
            ->with($expectedValue, $this->saleableInterfaceMock)
            ->willReturn($expectedValue);

        $result = $this->regularPrice->getMinimalPrice();
        $this->assertEquals($expectedValue, $result, 'Incorrect amount');

        //Calling a second time, should use cached value
        $result = $this->regularPrice->getMinimalPrice();
        $this->assertEquals($expectedValue, $result, 'Incorrect amount the second time');
    }
}
