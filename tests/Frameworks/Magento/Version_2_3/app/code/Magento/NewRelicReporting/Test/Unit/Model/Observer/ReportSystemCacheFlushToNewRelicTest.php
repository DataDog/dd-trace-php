<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\NewRelicReporting\Test\Unit\Model\Observer;

use Magento\NewRelicReporting\Model\Observer\ReportSystemCacheFlushToNewRelic;

/**
 * Class ReportSystemCacheFlushToNewRelicTest
 */
class ReportSystemCacheFlushToNewRelicTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var ReportSystemCacheFlushToNewRelic
     */
    protected $model;

    /**
     * @var \Magento\NewRelicReporting\Model\Config|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $config;

    /**
     * @var \Magento\Backend\Model\Auth\Session|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $backendAuthSession;

    /**
     * @var \Magento\NewRelicReporting\Model\Apm\DeploymentsFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $deploymentsFactory;

    /**
     * @var \Magento\NewRelicReporting\Model\Apm\Deployments|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $deploymentsModel;

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
        $this->backendAuthSession = $this->getMockBuilder(\Magento\Backend\Model\Auth\Session::class)
            ->disableOriginalConstructor()
            ->setMethods(['getUser'])
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
        $this->deploymentsFactory->expects($this->any())
            ->method('create')
            ->willReturn($this->deploymentsModel);

        $this->model = new ReportSystemCacheFlushToNewRelic(
            $this->config,
            $this->backendAuthSession,
            $this->deploymentsFactory
        );
    }

    /**
     * Test case when module is disabled in config
     *
     * @return void
     */
    public function testReportSystemCacheFlushToNewRelicModuleDisabledFromConfig()
    {
        /** @var \Magento\Framework\Event\Observer|\PHPUnit\Framework\MockObject\MockObject $eventObserver */
        $eventObserver = $this->getMockBuilder(\Magento\Framework\Event\Observer::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->config->expects($this->once())
            ->method('isNewRelicEnabled')
            ->willReturn(false);

        $this->model->execute($eventObserver);
    }

    /**
     * Test case when module is enabled in config
     *
     * @return void
     */
    public function testReportSystemCacheFlushToNewRelic()
    {
        /** @var \Magento\Framework\Event\Observer|\PHPUnit\Framework\MockObject\MockObject $eventObserver */
        $eventObserver = $this->getMockBuilder(\Magento\Framework\Event\Observer::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->config->expects($this->once())
            ->method('isNewRelicEnabled')
            ->willReturn(true);
        $userMock = $this->getMockBuilder(\Magento\User\Model\User::class)->disableOriginalConstructor()->getMock();
        $this->backendAuthSession->expects($this->once())
            ->method('getUser')
            ->willReturn($userMock);
        $userMock->expects($this->once())
            ->method('getId')
            ->willReturn('2');
        $this->deploymentsFactory->expects($this->once())
            ->method('create')
            ->willReturn($this->deploymentsModel);
        $this->deploymentsModel->expects($this->once())
            ->method('setDeployment')
            ->willReturnSelf();

        $this->model->execute($eventObserver);
    }
}
