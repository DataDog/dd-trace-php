<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Sales\Test\Unit\Model\CronJob;

use \Magento\Sales\Model\CronJob\AggregateSalesReportOrderData;

/**
 * Tests Magento\Sales\Model\CronJob\AggregateSalesReportOrderDataTest
 */
class AggregateSalesReportOrderDataTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\Locale\ResolverInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $localeResolverMock;

    /**
     * @var \Magento\Framework\Stdlib\DateTime\TimezoneInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $localeDateMock;

    /**
     * @var \Magento\Sales\Model\ResourceModel\Report\OrderFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $orderFactoryMock;

    /**
     * @var \Magento\Sales\Model\CronJob\AggregateSalesReportOrderData
     */
    protected $observer;

    protected function setUp(): void
    {
        $this->localeResolverMock = $this->getMockBuilder(\Magento\Framework\Locale\ResolverInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->orderFactoryMock = $this->getMockBuilder(\Magento\Sales\Model\ResourceModel\Report\OrderFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();
        $this->localeDateMock = $this->getMockBuilder(\Magento\Framework\Stdlib\DateTime\TimezoneInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->observer = new AggregateSalesReportOrderData(
            $this->localeResolverMock,
            $this->localeDateMock,
            $this->orderFactoryMock
        );
    }

    public function testExecute()
    {
        $date = $this->setupAggregate();
        $orderMock = $this->getMockBuilder(\Magento\Sales\Model\ResourceModel\Report\Order::class)
            ->disableOriginalConstructor()
            ->getMock();
        $orderMock->expects($this->once())
            ->method('aggregate')
            ->with($date);
        $this->orderFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($orderMock);
        $this->observer->execute();
    }

    /**
     * Set up aggregate
     *
     * @return \DateTime
     */
    protected function setupAggregate()
    {
        $this->localeResolverMock->expects($this->once())
            ->method('emulate')
            ->with(0);
        $this->localeResolverMock->expects($this->once())
            ->method('revert');

        $date = (new \DateTime())->sub(new \DateInterval('PT25H'));
        $this->localeDateMock->expects($this->once())
            ->method('date')
            ->willReturn($date);

        return $date;
    }
}
