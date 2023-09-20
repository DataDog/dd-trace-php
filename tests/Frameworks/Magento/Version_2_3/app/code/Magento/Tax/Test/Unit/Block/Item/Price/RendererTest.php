<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Tax\Test\Unit\Block\Item\Price;

use Magento\Framework\Pricing\Render;

class RendererTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Tax\Block\Item\Price\Renderer
     */
    protected $renderer;

    /**
     * @var \Magento\Tax\Helper\Data|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $taxHelper;

    /**
     * @var \Magento\Framework\Pricing\PriceCurrencyInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $priceCurrency;

    protected function setUp(): void
    {
        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);

        $this->priceCurrency = $this->getMockBuilder(
            \Magento\Framework\Pricing\PriceCurrencyInterface::class
        )->getMock();
        $this->taxHelper = $this->getMockBuilder(\Magento\Tax\Helper\Data::class)
            ->disableOriginalConstructor()
            ->setMethods([
                'displayCartPriceExclTax',
                'displayCartBothPrices',
                'displayCartPriceInclTax',
                'displaySalesPriceExclTax',
                'displaySalesBothPrices',
                'displaySalesPriceInclTax',
            ])
            ->getMock();

        $this->renderer = $objectManager->getObject(
            \Magento\Tax\Block\Item\Price\Renderer::class,
            [
                'taxHelper' => $this->taxHelper,
                'priceCurrency' => $this->priceCurrency,
                'data' => [
                    'zone' => Render::ZONE_CART,
                ]
            ]
        );
    }

    /**
     * @param $storeId
     * @return \PHPUnit\Framework\MockObject\MockObject|\Magento\Sales\Model\Order\Item
     */
    protected function getItemMockWithStoreId($storeId)
    {
        $itemMock = $this->getMockBuilder(\Magento\Sales\Model\Order\Item::class)
            ->disableOriginalConstructor()
            ->setMethods(['getStoreId', '__wakeup'])
            ->getMock();

        $itemMock->expects($this->once())
            ->method('getStoreId')
            ->willReturn($storeId);

        return $itemMock;
    }

    /**
     * Test displayPriceInclTax
     *
     * @param string $zone
     * @param string $methodName
     * @dataProvider displayPriceInclTaxDataProvider
     */
    public function testDisplayPriceInclTax($zone, $methodName)
    {
        $storeId = 1;
        $flag = true;

        $itemMock = $this->getItemMockWithStoreId($storeId);
        $this->renderer->setItem($itemMock);
        $this->renderer->setZone($zone);
        $this->taxHelper->expects($this->once())
            ->method($methodName)
            ->with($storeId)
            ->willReturn($flag);

        $this->assertEquals($flag, $this->renderer->displayPriceInclTax());
    }

    /**
     * @return array
     */
    public function displayPriceInclTaxDataProvider()
    {
        $data = [
            'cart' => [
                'zone' => Render::ZONE_CART,
                'method_name' => 'displayCartPriceInclTax',
            ],
            'anythingelse' => [
                'zone' => 'anythingelse',
                'method_name' => 'displayCartPriceInclTax',
            ],
            'sale' => [
                'zone' => Render::ZONE_SALES,
                'method_name' => 'displaySalesPriceInclTax',
            ],
            'email' => [
                'zone' => Render::ZONE_EMAIL,
                'method_name' => 'displaySalesPriceInclTax',
            ],
        ];

        return $data;
    }

    /**
     * Test displayPriceExclTax
     *
     * @param string $zone
     * @param string $methodName
     * @dataProvider displayPriceExclTaxDataProvider
     */
    public function testDisplayPriceExclTax($zone, $methodName)
    {
        $storeId = 1;
        $flag = true;

        $itemMock = $this->getItemMockWithStoreId($storeId);
        $this->renderer->setItem($itemMock);
        $this->renderer->setZone($zone);
        $this->taxHelper->expects($this->once())
            ->method($methodName)
            ->with($storeId)
            ->willReturn($flag);

        $this->assertEquals($flag, $this->renderer->displayPriceExclTax());
    }

    /**
     * @return array
     */
    public function displayPriceExclTaxDataProvider()
    {
        $data = [
            'cart' => [
                'zone' => Render::ZONE_CART,
                'method_name' => 'displayCartPriceExclTax',
            ],
            'anythingelse' => [
                'zone' => 'anythingelse',
                'method_name' => 'displayCartPriceExclTax',
            ],
            'sale' => [
                'zone' => Render::ZONE_SALES,
                'method_name' => 'displaySalesPriceExclTax',
            ],
            'email' => [
                'zone' => Render::ZONE_EMAIL,
                'method_name' => 'displaySalesPriceExclTax',
            ],
        ];

        return $data;
    }

    /**
     * Test displayBothPrices
     *
     * @param string $zone
     * @param string $methodName
     * @dataProvider displayBothPricesDataProvider
     */
    public function testDisplayBothPrices($zone, $methodName)
    {
        $storeId = 1;
        $flag = true;

        $itemMock = $this->getItemMockWithStoreId($storeId);
        $this->renderer->setItem($itemMock);
        $this->renderer->setZone($zone);
        $this->taxHelper->expects($this->once())
            ->method($methodName)
            ->with($storeId)
            ->willReturn($flag);

        $this->assertEquals($flag, $this->renderer->displayBothPrices());
    }

    /**
     * @return array
     */
    public function displayBothPricesDataProvider()
    {
        $data = [
            'cart' => [
                'zone' => Render::ZONE_CART,
                'method_name' => 'displayCartBothPrices',
            ],
            'anythingelse' => [
                'zone' => 'anythingelse',
                'method_name' => 'displayCartBothPrices',
            ],
            'sale' => [
                'zone' => Render::ZONE_SALES,
                'method_name' => 'displaySalesBothPrices',
            ],
            'email' => [
                'zone' => Render::ZONE_EMAIL,
                'method_name' => 'displaySalesBothPrices',
            ],
        ];

        return $data;
    }

    public function testFormatPriceQuoteItem()
    {
        $price = 3.554;
        $formattedPrice = "$3.55";

        $storeMock = $this->getMockBuilder(\Magento\Store\Model\Store::class)
            ->disableOriginalConstructor()
            ->setMethods(['formatPrice', '__wakeup'])
            ->getMock();

        $this->priceCurrency->expects($this->once())
            ->method('format')
            ->with($price, true)
            ->willReturn($formattedPrice);

        $itemMock = $this->getMockBuilder(\Magento\Quote\Model\Quote\Item::class)
            ->disableOriginalConstructor()
            ->setMethods(['getStore', '__wakeup'])
            ->getMock();

        $itemMock->expects($this->once())
            ->method('getStore')
            ->willReturn($storeMock);

        $this->renderer->setItem($itemMock);
        $this->assertEquals($formattedPrice, $this->renderer->formatPrice($price));
    }

    public function testFormatPriceOrderItem()
    {
        $price = 3.554;
        $formattedPrice = "$3.55";

        $orderMock = $this->getMockBuilder(\Magento\Sales\Model\Order::class)
            ->disableOriginalConstructor()
            ->getMock();

        $orderMock->expects($this->once())
            ->method('formatPrice')
            ->with($price, false)
            ->willReturn($formattedPrice);

        $itemMock = $this->getMockBuilder(\Magento\Sales\Model\Order\Item::class)
            ->disableOriginalConstructor()
            ->setMethods(['getOrder', '__wakeup'])
            ->getMock();

        $itemMock->expects($this->once())
            ->method('getOrder')
            ->willReturn($orderMock);

        $this->renderer->setItem($itemMock);
        $this->assertEquals($formattedPrice, $this->renderer->formatPrice($price));
    }

    public function testFormatPriceInvoiceItem()
    {
        $price = 3.554;
        $formattedPrice = "$3.55";

        $orderMock = $this->getMockBuilder(\Magento\Sales\Model\Order::class)
            ->disableOriginalConstructor()
            ->setMethods(['formatPrice', '__wakeup'])
            ->getMock();

        $orderMock->expects($this->once())
            ->method('formatPrice')
            ->with($price, false)
            ->willReturn($formattedPrice);

        $orderItemMock = $this->getMockBuilder(\Magento\Sales\Model\Order\Item::class)
            ->disableOriginalConstructor()
            ->setMethods(['getOrder', '__wakeup'])
            ->getMock();

        $orderItemMock->expects($this->once())
            ->method('getOrder')
            ->willReturn($orderMock);

        $invoiceItemMock = $this->getMockBuilder(\Magento\Sales\Model\Invoice\Item::class)
            ->disableOriginalConstructor()
            ->setMethods(['getOrderItem', '__wakeup', 'getStoreId'])
            ->getMock();

        $invoiceItemMock->expects($this->once())
            ->method('getOrderItem')
            ->willReturn($orderItemMock);

        $this->renderer->setItem($invoiceItemMock);
        $this->assertEquals($formattedPrice, $this->renderer->formatPrice($price));
    }

    public function testGetZone()
    {
        $this->assertEquals(Render::ZONE_CART, $this->renderer->getZone());
    }

    public function testGetStoreId()
    {
        $storeId = 'default';

        $itemMock = $this->getMockBuilder(\Magento\Quote\Model\Quote\Item::class)
            ->disableOriginalConstructor()
            ->setMethods(['getStoreId', '__wakeup'])
            ->getMock();

        $itemMock->expects($this->once())
            ->method('getStoreId')
            ->willReturn($storeId);

        $this->renderer->setItem($itemMock);
        $this->assertEquals($storeId, $this->renderer->getStoreId());
    }

    public function testGetItemDisplayPriceExclTaxQuoteItem()
    {
        $price = 10;

        /** @var \Magento\Quote\Model\Quote\Item|\PHPUnit\Framework\MockObject\MockObject $quoteItemMock */
        $quoteItemMock = $this->getMockBuilder(\Magento\Quote\Model\Quote\Item::class)
            ->disableOriginalConstructor()
            ->setMethods(['getCalculationPrice', '__wakeup'])
            ->getMock();

        $quoteItemMock->expects($this->once())
            ->method('getCalculationPrice')
            ->willReturn($price);

        $this->renderer->setItem($quoteItemMock);
        $this->assertEquals($price, $this->renderer->getItemDisplayPriceExclTax());
    }

    public function testGetItemDisplayPriceExclTaxOrderItem()
    {
        $price = 10;

        /** @var \Magento\Sales\Model\Order\Item|\PHPUnit\Framework\MockObject\MockObject $orderItemMock */
        $orderItemMock = $this->getMockBuilder(\Magento\Sales\Model\Order\Item::class)
            ->disableOriginalConstructor()
            ->setMethods(['getPrice', '__wakeup'])
            ->getMock();

        $orderItemMock->expects($this->once())
            ->method('getPrice')
            ->willReturn($price);

        $this->renderer->setItem($orderItemMock);
        $this->assertEquals($price, $this->renderer->getItemDisplayPriceExclTax());
    }

    public function testGetTotalAmount()
    {
        $rowTotal = 100;
        $taxAmount = 10;
        $discountTaxCompensationAmount = 2;
        $discountAmount = 20;

        $expectedValue = $rowTotal + $taxAmount + $discountTaxCompensationAmount - $discountAmount;

        $itemMock = $this->getMockBuilder(\Magento\Sales\Model\Order\Item::class)
            ->disableOriginalConstructor()
            ->setMethods(
                [
                'getRowTotal',
                'getTaxAmount',
                'getDiscountTaxCompensationAmount',
                'getDiscountAmount',
                '__wakeup'
                ]
            )
            ->getMock();

        $itemMock->expects($this->once())
            ->method('getRowTotal')
            ->willReturn($rowTotal);

        $itemMock->expects($this->once())
            ->method('getTaxAmount')
            ->willReturn($taxAmount);

        $itemMock->expects($this->once())
            ->method('getDiscountTaxCompensationAmount')
            ->willReturn($discountTaxCompensationAmount);

        $itemMock->expects($this->once())
            ->method('getDiscountAmount')
            ->willReturn($discountAmount);

        $this->assertEquals($expectedValue, $this->renderer->getTotalAmount($itemMock));
    }

    public function testGetBaseTotalAmount()
    {
        $baseRowTotal = 100;
        $baseTaxAmount = 10;
        $baseDiscountTaxCompensationAmount = 2;
        $baseDiscountAmount = 20;

        $expectedValue = 92;

        $itemMock = $this->getMockBuilder(\Magento\Sales\Model\Order\Item::class)
            ->disableOriginalConstructor()
            ->setMethods(
                [
                    'getBaseRowTotal',
                    'getBaseTaxAmount',
                    'getBaseDiscountTaxCompensationAmount',
                    'getBaseDiscountAmount',
                    '__wakeup'
                ]
            )
            ->getMock();

        $itemMock->expects($this->once())
            ->method('getBaseRowTotal')
            ->willReturn($baseRowTotal);

        $itemMock->expects($this->once())
            ->method('getBaseTaxAmount')
            ->willReturn($baseTaxAmount);

        $itemMock->expects($this->once())
            ->method('getBaseDiscountTaxCompensationAmount')
            ->willReturn($baseDiscountTaxCompensationAmount);

        $itemMock->expects($this->once())
            ->method('getBaseDiscountAmount')
            ->willReturn($baseDiscountAmount);

        $this->assertEquals($expectedValue, $this->renderer->getBaseTotalAmount($itemMock));
    }
}
