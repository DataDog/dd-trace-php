<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\NewRelicReporting\Test\Unit\Model\Cron;

use Magento\NewRelicReporting\Model\Cron\ReportNewRelicCron;

/**
 * Class ReportNewRelicCronTest
 */
class ReportNewRelicCronTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var ReportNewRelicCron
     */
    protected $model;

    /**
     * @var \Magento\NewRelicReporting\Model\Config|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $config;

    /**
     * @var \Magento\NewRelicReporting\Model\Module\Collect|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $collect;

    /**
     * @var \Magento\NewRelicReporting\Model\Counter|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $counter;

    /**
     * @var \Magento\NewRelicReporting\Model\CronEventFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $cronEventFactory;

    /**
     * @var \Magento\NewRelicReporting\Model\CronEvent|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $cronEventModel;

    /**
     * @var \Magento\NewRelicReporting\Model\Apm\DeploymentsFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $deploymentsFactory;

    /**
     * @var \Magento\NewRelicReporting\Model\Apm\Deployments|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $deploymentsModel;

    /**
     * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $logger;

    /**
     * Setup
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->config = $this->getMockBuilder(\Magento\NewRelicReporting\Model\Config::class)
            ->disableOriginalConstructor()
            ->setMethods(['isNewRelicEnabled'])
            ->getMock();
        $this->collect = $this->getMockBuilder(\Magento\NewRelicReporting\Model\Module\Collect::class)
            ->disableOriginalConstructor()
            ->setMethods(['getModuleData'])
            ->getMock();
        $this->counter = $this->getMockBuilder(\Magento\NewRelicReporting\Model\Counter::class)
            ->disableOriginalConstructor()
            ->setMethods([
                'getAllProductsCount',
                'getConfigurableCount',
                'getActiveCatalogSize',
                'getCategoryCount',
                'getWebsiteCount',
                'getStoreViewsCount',
                'getCustomerCount',
            ])
            ->getMock();
        $this->cronEventFactory = $this->getMockBuilder(\Magento\NewRelicReporting\Model\CronEventFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();
        $this->cronEventModel = $this->getMockBuilder(\Magento\NewRelicReporting\Model\CronEvent::class)
            ->disableOriginalConstructor()
            ->setMethods(['addData', 'sendRequest'])
            ->getMock();
        $this->deploymentsFactory = $this->getMockBuilder(
            \Magento\NewRelicReporting\Model\Apm\DeploymentsFactory::class
        )->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();
        $this->deploymentsModel = $this->getMockBuilder(\Magento\NewRelicReporting\Model\Apm\Deployments::class)
            ->disableOriginalConstructor()
            ->setMethods(['setDeployment'])
            ->getMock();

        $this->cronEventFactory->expects($this->any())
            ->method('create')
            ->willReturn($this->cronEventModel);
        $this->deploymentsFactory->expects($this->any())
            ->method('create')
            ->willReturn($this->deploymentsModel);
        $this->logger = $this->getMockForAbstractClass(\Psr\Log\LoggerInterface::class);

        $this->model = new ReportNewRelicCron(
            $this->config,
            $this->collect,
            $this->counter,
            $this->cronEventFactory,
            $this->deploymentsFactory,
            $this->logger
        );
    }

    /**
     * Test case when module is disabled in config
     *
     * @return void
     */
    public function testReportNewRelicCronModuleDisabledFromConfig()
    {
        $this->config->expects($this->once())
            ->method('isNewRelicEnabled')
            ->willReturn(false);

        $this->assertSame(
            $this->model,
            $this->model->report()
        );
    }

    /**
     * Test case when module is enabled
     *
     * @return void
     */
    public function testReportNewRelicCron()
    {

        $this->config->expects($this->once())
            ->method('isNewRelicEnabled')
            ->willReturn(true);
        $this->counter->expects($this->once())
            ->method('getAllProductsCount');
        $this->counter->expects($this->once())
            ->method('getConfigurableCount');
        $this->counter->expects($this->once())
            ->method('getActiveCatalogSize');
        $this->counter->expects($this->once())
            ->method('getCategoryCount');
        $this->counter->expects($this->once())
            ->method('getWebsiteCount');
        $this->counter->expects($this->once())
            ->method('getStoreViewsCount');
        $this->counter->expects($this->once())
            ->method('getCustomerCount');
        $this->cronEventModel->expects($this->once())
            ->method('addData')
            ->willReturnSelf();
        $this->cronEventModel->expects($this->once())
            ->method('sendRequest');

        $this->deploymentsModel->expects($this->any())
            ->method('setDeployment');

        $this->assertSame(
            $this->model,
            $this->model->report()
        );
    }

    /**
     * Test case when module is enabled and request is failed
     *
     */
    public function testReportNewRelicCronRequestFailed()
    {
        $this->expectException(\Exception::class);


        $this->config->expects($this->once())
            ->method('isNewRelicEnabled')
            ->willReturn(true);
        $this->counter->expects($this->once())
            ->method('getAllProductsCount');
        $this->counter->expects($this->once())
            ->method('getConfigurableCount');
        $this->counter->expects($this->once())
            ->method('getActiveCatalogSize');
        $this->counter->expects($this->once())
            ->method('getCategoryCount');
        $this->counter->expects($this->once())
            ->method('getWebsiteCount');
        $this->counter->expects($this->once())
            ->method('getStoreViewsCount');
        $this->counter->expects($this->once())
            ->method('getCustomerCount');
        $this->cronEventModel->expects($this->once())
            ->method('addData')
            ->willReturnSelf();
        $this->cronEventModel->expects($this->once())
            ->method('sendRequest');

        $this->cronEventModel->expects($this->once())->method('sendRequest')->willThrowException(new \Exception());
        $this->logger->expects($this->never())->method('critical');

        $this->deploymentsModel->expects($this->any())
            ->method('setDeployment');

        $this->model->report();
    }
}
