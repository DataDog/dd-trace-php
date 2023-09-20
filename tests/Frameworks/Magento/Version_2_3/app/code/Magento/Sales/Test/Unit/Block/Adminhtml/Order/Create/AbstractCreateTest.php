<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Sales\Test\Unit\Block\Adminhtml\Order\Create;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Pricing\Price\FinalPrice;

class AbstractCreateTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Sales\Block\Adminhtml\Order\Create\AbstractCreate|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $model;

    /**
     * @var \Magento\Catalog\Model\Product|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $productMock;

    /**
     * @var \Magento\Framework\Pricing\PriceInfo\Base|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $priceInfoMock;

    /**
     * @var \Magento\Downloadable\Pricing\Price\LinkPrice|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $linkPriceMock;

    protected function setUp(): void
    {
        $this->model = $this->getMockBuilder(\Magento\Sales\Block\Adminhtml\Order\Create\AbstractCreate::class)
            ->setMethods(['convertPrice'])
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $this->priceInfoMock = $this->getMockBuilder(\Magento\Framework\Pricing\PriceInfo\Base::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->productMock = $this->getMockBuilder(\Magento\Catalog\Model\Product::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->linkPriceMock = $this->getMockBuilder(\Magento\Downloadable\Pricing\Price\LinkPrice::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->productMock->expects($this->any())
            ->method('getPriceInfo')
            ->willReturn($this->priceInfoMock);
    }

    public function testGetItemPrice()
    {
        $price = 5.6;
        $resultPrice = 9.3;

        $this->linkPriceMock->expects($this->once())
            ->method('getValue')
            ->willReturn($price);
        $this->priceInfoMock->expects($this->once())
            ->method('getPrice')
            ->with(FinalPrice::PRICE_CODE)
            ->willReturn($this->linkPriceMock);
        $this->model->expects($this->once())
            ->method('convertPrice')
            ->with($price)
            ->willReturn($resultPrice);
        $this->assertEquals($resultPrice, $this->model->getItemPrice($this->productMock));
    }

    /**
     * @param $item
     *
     * @dataProvider getProductDataProvider
     */
    public function testGetProduct($item)
    {
        $product = $this->model->getProduct($item);

        self::assertInstanceOf(Product::class, $product);
    }

    /**
     * DataProvider for testGetProduct.
     *
     * @return array
     */
    public function getProductDataProvider()
    {
        $productMock = $this->createMock(Product::class);

        $itemMock = $this->createMock(\Magento\Wishlist\Model\Item::class);
        $itemMock->expects($this->once())->method('getProduct')->willReturn($productMock);

        return [
            [$productMock],
            [$itemMock],
        ];
    }
}
