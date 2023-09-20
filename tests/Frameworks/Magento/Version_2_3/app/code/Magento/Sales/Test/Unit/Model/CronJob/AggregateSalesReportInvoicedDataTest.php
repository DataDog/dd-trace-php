<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Sales\Test\Unit\Model\CronJob;

use \Magento\Sales\Model\CronJob\AggregateSalesReportInvoicedData;

/**
 * Tests Magento\Sales\Model\CronJob\AggregateSalesReportInvoicedDataTest
 */
class AggregateSalesReportInvoicedDataTest extends \PHPUnit\Framework\TestCase
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
     * @var \Magento\Sales\Model\ResourceModel\Report\InvoicedFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $invoicedFactoryMock;

    /**
     * @var \Magento\Sales\Model\CronJob\AggregateSalesReportInvoicedData
     */
    protected $observer;

    protected function setUp(): void
    {
        $this->localeResolverMock = $this->getMockBuilder(\Magento\Framework\Locale\ResolverInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->invoicedFactoryMock = $this->getMockBuilder(
            \Magento\Sales\Model\ResourceModel\Report\InvoicedFactory::class
        )
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();
        $this->localeDateMock = $this->getMockBuilder(\Magento\Framework\Stdlib\DateTime\TimezoneInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->observer = new AggregateSalesReportInvoicedData(
            $this->localeResolverMock,
            $this->localeDateMock,
            $this->invoicedFactoryMock
        );
    }

    public function testExecute()
    {
        $date = $this->setupAggregate();
        $invoicedMock = $this->getMockBuilder(\Magento\Sales\Model\ResourceModel\Report\Invoiced::class)
            ->disableOriginalConstructor()
            ->getMock();
        $invoicedMock->expects($this->once())
            ->method('aggregate')
            ->with($date);
        $this->invoicedFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($invoicedMock);
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
