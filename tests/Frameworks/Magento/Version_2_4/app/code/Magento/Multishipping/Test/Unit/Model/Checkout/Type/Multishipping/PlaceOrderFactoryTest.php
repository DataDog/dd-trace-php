<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Multishipping\Test\Unit\Model\Checkout\Type\Multishipping;

use Magento\Framework\ObjectManagerInterface;
use Magento\Multishipping\Model\Checkout\Type\Multishipping\PlaceOrderDefault;
use Magento\Multishipping\Model\Checkout\Type\Multishipping\PlaceOrderFactory;
use Magento\Multishipping\Model\Checkout\Type\Multishipping\PlaceOrderInterface;
use Magento\Multishipping\Model\Checkout\Type\Multishipping\PlaceOrderPool;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests Magento\Multishipping\Model\Checkout\Type\Multishipping\PlaceOrderFactory.
 */
class PlaceOrderFactoryTest extends TestCase
{
    /**
     * @var ObjectManagerInterface|MockObject
     */
    private $objectManager;

    /**
     * @var PlaceOrderPool|MockObject
     */
    private $placeOrderPool;

    /**
     * @var PlaceOrderFactory
     */
    private $placeOrderFactory;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->objectManager = $this->getMockForAbstractClass(ObjectManagerInterface::class);

        $this->placeOrderPool = $this->getMockBuilder(PlaceOrderPool::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->placeOrderFactory = new PlaceOrderFactory($this->objectManager, $this->placeOrderPool);
    }

    /**
     * Checks instantiation of place order service.
     *
     * @return void
     */
    public function testCreate()
    {
        $paymentProviderCode = 'code';

        $placeOrder = $this->getMockForAbstractClass(PlaceOrderInterface::class);
        $this->placeOrderPool->method('get')
            ->with($paymentProviderCode)
            ->willReturn($placeOrder);

        $instance = $this->placeOrderFactory->create($paymentProviderCode);

        $this->assertInstanceOf(PlaceOrderInterface::class, $instance);
    }

    /**
     * Checks that default place order service is created when place order pull returns null.
     *
     * @return void
     */
    public function testCreateWithDefault()
    {
        $paymentProviderCode = 'code';

        $this->placeOrderPool->method('get')
            ->with($paymentProviderCode)
            ->willReturn(null);
        $placeOrder = $this->getMockBuilder(PlaceOrderDefault::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->objectManager->method('get')
            ->with(PlaceOrderDefault::class)
            ->willReturn($placeOrder);

        $instance = $this->placeOrderFactory->create($paymentProviderCode);

        $this->assertInstanceOf(PlaceOrderDefault::class, $instance);
    }
}
