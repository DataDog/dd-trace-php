<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Multishipping\Test\Unit\Block\Checkout;

use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Multishipping\Block\Checkout\Shipping;

class ShippingTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var Shipping
     */
    protected $model;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $multiShippingMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $scopeConfigMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $priceCurrencyMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $taxHelperMock;

    protected function setUp(): void
    {
        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->scopeConfigMock = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $this->multiShippingMock =
            $this->createMock(\Magento\Multishipping\Model\Checkout\Type\Multishipping::class);
        $this->priceCurrencyMock =
            $this->createMock(\Magento\Framework\Pricing\PriceCurrencyInterface::class);
        $this->taxHelperMock = $this->createMock(\Magento\Tax\Helper\Data::class);
        $this->model = $objectManager->getObject(
            \Magento\Multishipping\Block\Checkout\Shipping::class,
            [
                'multishipping' => $this->multiShippingMock,
                'scopeConfig' => $this->scopeConfigMock,
                'priceCurrency' => $this->priceCurrencyMock,
                'taxHelper' => $this->taxHelperMock
            ]
        );
    }

    public function testGetAddresses()
    {
        $quoteMock = $this->createMock(\Magento\Quote\Model\Quote::class);
        $this->multiShippingMock->expects($this->once())->method('getQuote')->willReturn($quoteMock);
        $quoteMock->expects($this->once())
            ->method('getAllShippingAddresses')->willReturn(['expected array']);
        $this->assertEquals(['expected array'], $this->model->getAddresses());
    }

    public function testGetAddressShippingMethod()
    {
        $addressMock = $this->createPartialMock(
            \Magento\Quote\Model\Quote\Address::class,
            ['getShippingMethod', '__wakeup']
        );
        $addressMock->expects($this->once())
            ->method('getShippingMethod')->willReturn('expected shipping method');
        $this->assertEquals('expected shipping method', $this->model->getAddressShippingMethod($addressMock));
    }

    public function testGetShippingRates()
    {
        $addressMock = $this->createPartialMock(
            \Magento\Quote\Model\Quote\Address::class,
            ['getGroupedAllShippingRates', '__wakeup']
        );

        $addressMock->expects($this->once())
            ->method('getGroupedAllShippingRates')->willReturn(['expected array']);
        $this->assertEquals(['expected array'], $this->model->getShippingRates($addressMock));
    }

    public function testGetCarrierName()
    {
        $carrierCode = 'some carrier code';
        $name = 'some name';
        $this->scopeConfigMock->expects($this->once())->method('getValue')->with(
            'carriers/' . $carrierCode . '/title',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        )->willReturn($name);

        $this->assertEquals($name, $this->model->getCarrierName($carrierCode));
    }

    public function testGetCarrierNameWithEmptyName()
    {
        $carrierCode = 'some carrier code';
        $this->scopeConfigMock->expects($this->once())->method('getValue')->with(
            'carriers/' . $carrierCode . '/title',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        )->willReturn(null);

        $this->assertEquals($carrierCode, $this->model->getCarrierName($carrierCode));
    }

    public function testGetShippingPrice()
    {
        $addressMock = $this->createPartialMock(\Magento\Quote\Model\Quote\Address::class, ['getQuote', '__wakeup']);
        $quoteMock = $this->createMock(\Magento\Quote\Model\Quote::class);
        $storeMock = $this->createMock(\Magento\Store\Model\Store::class);
        $price = 100;
        $flag = true;
        $shippingPrice = 11.11;
        $this->taxHelperMock->expects($this->once())
            ->method('getShippingPrice')->with($price, $flag, $addressMock)->willReturn($shippingPrice);
        $addressMock->expects($this->once())->method('getQuote')->willReturn($quoteMock);
        $quoteMock->expects($this->once())->method('getStore')->willReturn($storeMock);

        $this->priceCurrencyMock->expects($this->once())
            ->method('convertAndFormat')
            ->with(
                $shippingPrice,
                true,
                PriceCurrencyInterface::DEFAULT_PRECISION,
                $storeMock
            );

        $this->model->getShippingPrice($addressMock, $price, $flag);
    }
}
