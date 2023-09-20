<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Quote\Test\Unit\Model\Quote\Address\Total;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ShippingTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Quote\Model\Quote\Address\Total\Shipping
     */
    protected $shippingModel;

    /** @var \Magento\Quote\Model\Quote|\PHPUnit\Framework\MockObject\MockObject */
    protected $quote;

    /** @var \Magento\Quote\Model\Quote\Address\Total|\PHPUnit\Framework\MockObject\MockObject  */
    protected $total;

    /** @var \Magento\Quote\Api\Data\ShippingAssignmentInterface|\PHPUnit\Framework\MockObject\MockObject  */
    protected $shippingAssignment;

    /** @var \Magento\Quote\Model\Quote\Address|\PHPUnit\Framework\MockObject\MockObject  */
    protected $address;

    /** @var \Magento\Quote\Api\Data\ShippingInterface|\PHPUnit\Framework\MockObject\MockObject  */
    protected $shipping;

    /** @var \Magento\Quote\Model\Quote\Address\FreeShippingInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $freeShipping;

    /** @var \Magento\Quote\Api\Data\CartItemInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $cartItem;

    /** @var \Magento\Quote\Model\Quote\Address\Rate|\PHPUnit\Framework\MockObject\MockObject */
    protected $rate;

    /** @var \Magento\Store\Model\Store|\PHPUnit\Framework\MockObject\MockObject */
    protected $store;

    /** @var \Magento\Framework\Pricing\PriceCurrencyInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $priceCurrency;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->freeShipping = $this->getMockForAbstractClass(
            \Magento\Quote\Model\Quote\Address\FreeShippingInterface::class,
            [],
            '',
            false
        );
        $this->priceCurrency = $this->getMockForAbstractClass(
            \Magento\Framework\Pricing\PriceCurrencyInterface::class,
            [],
            '',
            false
        );
        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->shippingModel = $objectManager->getObject(
            \Magento\Quote\Model\Quote\Address\Total\Shipping::class,
            [
                'freeShipping' => $this->freeShipping,
                'priceCurrency' => $this->priceCurrency,
            ]
        );

        $this->quote = $this->createMock(\Magento\Quote\Model\Quote::class);
        $this->total = $this->createPartialMock(\Magento\Quote\Model\Quote\Address\Total::class, [
                'setShippingAmount',
                'setBaseShippingAmount',
                'setBaseTotalAmount',
                'setTotalAmount',
                'setShippingDescription',
            ]);
        $this->shippingAssignment = $this->getMockForAbstractClass(
            \Magento\Quote\Api\Data\ShippingAssignmentInterface::class,
            [],
            '',
            false
        );
        $this->address = $this->createPartialMock(\Magento\Quote\Model\Quote\Address::class, [
                'setWeight',
                'setFreeMethodWeight',
                'getWeight',
                'getFreeMethodWeight',
                'setFreeShipping',
                'setItemQty',
                'collectShippingRates',
                'getAllShippingRates',
                'setShippingDescription',
                'getShippingDescription',
                'getFreeShipping',
            ]);
        $this->shipping = $this->getMockForAbstractClass(
            \Magento\Quote\Api\Data\ShippingInterface::class,
            [],
            '',
            false
        );
        $this->cartItem = $this->getMockForAbstractClass(
            \Magento\Quote\Api\Data\CartItemInterface::class,
            [],
            '',
            false,
            false,
            true,
            [
                'getFreeShipping',
                'getProduct',
                'getParentItem',
                'getHasChildren',
                'isVirtual',
                'getWeight',
                'getQty',
                'setRowWeight',
            ]
        );
        $this->rate = $this->createPartialMock(
            \Magento\Quote\Model\Quote\Address\Rate::class,
            ['getPrice', 'getCode', 'getCarrierTitle', 'getMethodTitle']
        );
        $this->store = $this->createMock(\Magento\Store\Model\Store::class);
    }

    /**
     * @return void
     */
    public function testFetch(): void
    {
        $shippingAmount = 100;
        $shippingDescription = 100;
        $expectedResult = [
            'code' => 'shipping',
            'value' => 100,
            'title' => __('Shipping & Handling (%1)', $shippingDescription)
        ];

        $quoteMock = $this->createMock(\Magento\Quote\Model\Quote::class);
        $totalMock = $this->createPartialMock(
            \Magento\Quote\Model\Quote\Address\Total::class,
            ['getShippingAmount', 'getShippingDescription']
        );

        $totalMock->expects($this->once())->method('getShippingAmount')->willReturn($shippingAmount);
        $totalMock->expects($this->once())->method('getShippingDescription')->willReturn($shippingDescription);
        $this->assertEquals($expectedResult, $this->shippingModel->fetch($quoteMock, $totalMock));
    }

    /**
     * @return void
     */
    public function testCollect(): void
    {
        $this->shippingAssignment->expects($this->exactly(3))
            ->method('getShipping')
            ->willReturn($this->shipping);
        $this->shipping->expects($this->exactly(2))
            ->method('getAddress')
            ->willReturn($this->address);
        $this->shipping->expects($this->once())
            ->method('getMethod')
            ->willReturn('flatrate');
        $this->shippingAssignment->expects($this->atLeastOnce())
            ->method('getItems')
            ->willReturn([$this->cartItem]);
        $this->freeShipping->method('isFreeShipping')
            ->with($this->quote, [$this->cartItem])
            ->willReturn(true);
        $this->address->method('setFreeShipping')
            ->with(true);
        $this->total->expects($this->atLeastOnce())
            ->method('setTotalAmount');
        $this->total->expects($this->atLeastOnce())
            ->method('setBaseTotalAmount');
        $this->cartItem->expects($this->atLeastOnce())
            ->method('getProduct')
            ->willReturnSelf();
        $this->cartItem->expects($this->atLeastOnce())
            ->method('isVirtual')
            ->willReturn(false);
        $this->cartItem->method('getParentItem')
            ->willReturn(false);
        $this->cartItem->method('getHasChildren')
            ->willReturn(false);
        $this->cartItem->method('getWeight')
            ->willReturn(2);
        $this->cartItem->expects($this->atLeastOnce())
            ->method('getQty')
            ->willReturn(2);
        $this->freeShippingAssertions();
        $this->cartItem->method('setRowWeight')
            ->with(0);
        $this->address->method('setItemQty')
            ->with(2);
        $this->address->expects($this->atLeastOnce())
            ->method('setWeight');
        $this->address->expects($this->atLeastOnce())
            ->method('setFreeMethodWeight');
        $this->address->expects($this->once())
            ->method('collectShippingRates');
        $this->address->expects($this->once())
            ->method('getAllShippingRates')
            ->willReturn([$this->rate]);
        $this->rate->expects($this->once())
            ->method('getCode')
            ->willReturn('flatrate');
        $this->quote->expects($this->once())
            ->method('getStore')
            ->willReturn($this->store);
        $this->rate->expects($this->atLeastOnce())
            ->method('getPrice')
            ->willReturn(5);
        $this->priceCurrency->expects($this->once())
            ->method('convert')
            ->with(5, $this->store)
            ->willReturn(10);
        $this->total->expects($this->once())
            ->method('setShippingAmount')
            ->with(10);
        $this->total->expects($this->once())
            ->method('setBaseShippingAmount')
            ->with(5);
        $this->rate->expects($this->once())
            ->method('getCarrierTitle')
            ->willReturn('Carrier title');
        $this->rate->expects($this->once())
            ->method('getMethodTitle')
            ->willReturn('Method title');
        $this->address->expects($this->once())
            ->method('setShippingDescription')
            ->with('Carrier title - Method title');
        $this->address->expects($this->once())
            ->method('getShippingDescription')
            ->willReturn('Carrier title - Method title');
        $this->total->expects($this->once())
            ->method('setShippingDescription')
            ->with('Carrier title - Method title');

        $this->shippingModel->collect($this->quote, $this->shippingAssignment, $this->total);
    }

    /**
     * @return void
     */
    protected function freeShippingAssertions(): void
    {
        $this->address->expects($this->at(0))
            ->method('getFreeShipping')
            ->willReturn(false);
        $this->address->expects($this->at(1))
            ->method('getFreeShipping')
            ->willReturn(true);
        $this->cartItem->expects($this->atLeastOnce())
            ->method('getFreeShipping')
            ->willReturn(true);
    }
}
