<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Tax\Test\Unit\Ui\DataProvider\Product\Listing\Collector;

use Magento\Catalog\Api\Data\ProductRender\PriceInfoExtensionInterface;
use Magento\Catalog\Api\Data\ProductRender\PriceInfoExtensionInterfaceFactory;
use Magento\Catalog\Api\Data\ProductRender\PriceInfoInterface;
use Magento\Catalog\Api\Data\ProductRender\PriceInfoInterfaceFactory;
use Magento\Catalog\Api\Data\ProductRenderInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ProductRender\FormattedPriceInfoBuilder;
use Magento\Catalog\Pricing\Price\FinalPrice;
use Magento\Framework\Pricing\Amount\AmountInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Tax\Ui\DataProvider\Product\Listing\Collector\Tax;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TaxTest extends TestCase
{
    /**
     * @var Tax
     */
    protected $model;

    /**
     * @var PriceCurrencyInterface|MockObject
     */
    protected $priceCurrencyMock;

    /**
     * @var PriceInfoInterface|MockObject
     */
    private $priceMock;

    /**
     * @var PriceInfoInterfaceFactory|MockObject
     */
    private $priceInfoFactory;

    /**
     * @var PriceInfoExtensionInterface|MockObject
     */
    private $extensionAttributes;

    /**
     * @var PriceInfoExtensionInterfaceFactory|MockObject
     */
    private $priceInfoExtensionFactory;

    /**
     * @var FormattedPriceInfoBuilder|MockObject
     */
    private $formattedPriceInfoBuilder;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->priceCurrencyMock = $this->getMockBuilder(PriceCurrencyInterface::class)
            ->getMockForAbstractClass();

        $this->priceMock = $this->getMockBuilder(PriceInfoInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $this->extensionAttributes = $this->getMockBuilder(PriceInfoExtensionInterface::class)
            ->setMethods(['setTaxAdjustments'])
            ->getMockForAbstractClass();

        $this->priceInfoFactory = $this->getMockBuilder(PriceInfoInterfaceFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();

        $this->priceInfoExtensionFactory = $this->getMockBuilder(PriceInfoExtensionInterfaceFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();
        $this->formattedPriceInfoBuilder = $this->getMockBuilder(FormattedPriceInfoBuilder::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->model = new Tax(
            $this->priceCurrencyMock,
            $this->priceInfoExtensionFactory,
            $this->priceInfoFactory,
            $this->formattedPriceInfoBuilder
        );
    }

    /**
     * @return void
     */
    public function testCollect()
    {
        $amountValue = 10;
        $minAmountValue = 5;
        $storeId = 1;
        $currencyCode = 'usd';

        $productMock = $this->getMockBuilder(Product::class)
            ->disableOriginalConstructor()
            ->getMock();
        $productRender = $this->getMockBuilder(ProductRenderInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $price = $this->getMockBuilder(FinalPrice::class)
            ->disableOriginalConstructor()
            ->getMock();
        $priceInfo = $this->getMockBuilder(PriceInfoInterface::class)
            ->setMethods(['getPrice'])
            ->getMockForAbstractClass();
        $amount = $this->getMockBuilder(AmountInterface::class)
            ->getMockForAbstractClass();
        $minAmount = $this->getMockBuilder(AmountInterface::class)
            ->getMockForAbstractClass();

        $priceInfo->expects($this->exactly(4))
            ->method('getPrice')
            ->willReturn($price);
        $this->priceInfoFactory->expects($this->once())
            ->method('create')
            ->willReturn($priceInfo);
        $productRender->expects($this->once())
            ->method('getPriceInfo')
            ->willReturn($priceInfo);
        $productMock->expects($this->any())
            ->method('getPriceInfo')
            ->willReturn($priceInfo);
        $priceInfo->expects($this->once())
            ->method('getExtensionAttributes')
            ->willReturn($this->extensionAttributes);
        $priceInfo->expects($this->atLeastOnce())
            ->method('getPrice')
            ->willReturn($price);

        $price->expects($this->once())
            ->method('getMaximalPrice')
            ->willReturn($amount);
        $price->expects($this->once())
            ->method('getMinimalPrice')
            ->willReturn($minAmount);
        $price->expects($this->exactly(2))
            ->method('getAmount')
            ->willReturn($amount);
        $amount->expects($this->exactly(3))
            ->method('getValue')
            ->willReturn($amountValue);
        $minAmount->expects($this->once())
            ->method('getValue')
            ->willReturn($minAmountValue);
        $productRender->expects($this->once())
            ->method('setPriceInfo')
            ->with($priceInfo);

        $productRender->expects($this->once())
            ->method('getStoreId')
            ->willReturn(1);
        $productRender->expects($this->once())
            ->method('getCurrencyCode')
            ->willReturn($currencyCode);

        $this->formattedPriceInfoBuilder->expects($this->once())
            ->method('build')
            ->with($priceInfo, $storeId, $currencyCode);

        $this->model->collect($productMock, $productRender);
    }
}
