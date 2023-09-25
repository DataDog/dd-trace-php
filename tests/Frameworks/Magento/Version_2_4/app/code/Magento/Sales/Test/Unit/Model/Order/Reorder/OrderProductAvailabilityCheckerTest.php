<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Sales\Test\Unit\Model\Order\Reorder;

use Magento\ConfigurableProductSales\Model\Order\Reorder\OrderedProductAvailabilityChecker as ConfigurableChecker;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Model\Order\Item;
use Magento\Sales\Model\Order\Reorder\OrderedProductAvailabilityChecker;
use Magento\Sales\Model\Order\Reorder\OrderedProductAvailabilityCheckerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class OrderProductAvailabilityCheckerTest extends TestCase
{
    /**
     * @var OrderedProductAvailabilityCheckerInterface[]
     */
    private $productAvailabilityChecks;

    /**
     * @var Item|MockObject
     */
    private $orderItemMock;

    /**
     * @var OrderItemInterface|MockObject
     */
    private $orderItemInterfaceMock;

    /**
     * @var ConfigurableChecker|MockObject
     */
    private $configurableCheckerMock;

    /**
     * @var string
     */
    private $productTypeConfigurable;

    /**
     * @var string
     */
    private $productTypeSimple;

    /**
     * @var OrderedProductAvailabilityChecker
     */
    private $checker;

    protected function setUp(): void
    {
        $objectManager = new ObjectManager($this);
        $this->orderItemMock = $this->getMockBuilder(Item::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->orderItemInterfaceMock = $this->getMockBuilder(OrderItemInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $this->productTypeConfigurable = 'configurable';
        $this->productTypeSimple = 'simple';
        $this->configurableCheckerMock = $this->getMockBuilder(ConfigurableChecker::class)
            ->disableOriginalConstructor()
            ->getMock();
        $fakeClass = new \stdClass();
        $this->productAvailabilityChecks[$this->productTypeConfigurable] = $this->configurableCheckerMock;
        $this->productAvailabilityChecks[$this->productTypeSimple] = $fakeClass;

        $this->checker = $objectManager->getObject(
            OrderedProductAvailabilityChecker::class,
            [
                'productAvailabilityChecks' => $this->productAvailabilityChecks
            ]
        );
    }

    public function testIsAvailableTrue()
    {
        $this->getProductType($this->productTypeConfigurable);
        $this->isAvailable(true);
        $this->assertTrue($this->checker->isAvailable($this->orderItemMock));
    }

    public function testIsAvailableFalse()
    {
        $this->getProductType($this->productTypeConfigurable);
        $this->isAvailable(false);
        $this->assertFalse($this->checker->isAvailable($this->orderItemMock));
    }

    public function testIsAvailableException()
    {
        $this->expectException('Magento\Framework\Exception\ConfigurationMismatchException');
        $this->getProductType($this->productTypeSimple);
        $this->checker->isAvailable($this->orderItemMock);
    }

    public function testIsAvailableTypeNotChecks()
    {
        $this->getProductType('test_type');
        $this->assertTrue($this->checker->isAvailable($this->orderItemMock));
    }

    /**
     * @param string $productType
     */
    private function getProductType($productType)
    {
        $this->orderItemMock->expects($this->any())
            ->method('getParentItem')
            ->willReturn($this->orderItemInterfaceMock);
        $this->orderItemInterfaceMock->expects($this->any())
            ->method('getProductType')
            ->willReturn($productType);
    }

    /**
     * @param bool $result
     */
    private function isAvailable($result)
    {
        $this->configurableCheckerMock->expects($this->once())
            ->method('isAvailable')
            ->with($this->orderItemMock)
            ->willReturn($result);
    }
}
