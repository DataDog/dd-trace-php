<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\NewRelicReporting\Test\Unit\Model\Observer;

use Magento\NewRelicReporting\Model\Observer\ReportConcurrentAdmins;

/**
 * Class ReportConcurrentAdminsTest
 */
class ReportConcurrentAdminsTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var ReportConcurrentAdmins
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
     * @var \Magento\NewRelicReporting\Model\UsersFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $usersFactory;

    /**
     * @var \Magento\NewRelicReporting\Model\Users|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $usersModel;

    /**
     * @var \Magento\Framework\Json\EncoderInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $jsonEncoder;

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
            ->setMethods(['isLoggedIn', 'getUser'])
            ->getMock();
        $this->usersFactory = $this->getMockBuilder(\Magento\NewRelicReporting\Model\UsersFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();
        $this->usersModel = $this->getMockBuilder(\Magento\NewRelicReporting\Model\Users::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->jsonEncoder = $this->getMockBuilder(\Magento\Framework\Json\EncoderInterface::class)
            ->getMock();

        $this->usersFactory->expects($this->any())
            ->method('create')
            ->willReturn($this->usersModel);

        $this->model = new ReportConcurrentAdmins(
            $this->config,
            $this->backendAuthSession,
            $this->usersFactory,
            $this->jsonEncoder
        );
    }

    /**
     * Test case when module is disabled in config
     *
     * @return void
     */
    public function testReportConcurrentAdminsModuleDisabledFromConfig()
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
     * Test case when user is not logged in
     *
     * @return void
     */
    public function testReportConcurrentAdminsUserIsNotLoggedIn()
    {
        /** @var \Magento\Framework\Event\Observer|\PHPUnit\Framework\MockObject\MockObject $eventObserver */
        $eventObserver = $this->getMockBuilder(\Magento\Framework\Event\Observer::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->config->expects($this->once())
            ->method('isNewRelicEnabled')
            ->willReturn(true);
        $this->backendAuthSession->expects($this->once())
            ->method('isLoggedIn')
            ->willReturn(false);

        $this->model->execute($eventObserver);
    }

    /**
     * Test case when module is enabled and user is logged in
     *
     * @return void
     */
    public function testReportConcurrentAdmins()
    {
        $testAction = 'JSON string';

        /** @var \Magento\Framework\Event\Observer|\PHPUnit\Framework\MockObject\MockObject $eventObserver */
        $eventObserver = $this->getMockBuilder(\Magento\Framework\Event\Observer::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->config->expects($this->once())
            ->method('isNewRelicEnabled')
            ->willReturn(true);
        $this->backendAuthSession->expects($this->once())
            ->method('isLoggedIn')
            ->willReturn(true);
        $userMock = $this->getMockBuilder(\Magento\User\Model\User::class)->disableOriginalConstructor()->getMock();
        $this->backendAuthSession->expects($this->once())
            ->method('getUser')
            ->willReturn($userMock);
        $this->jsonEncoder->expects($this->once())
            ->method('encode')
            ->willReturn($testAction);
        $this->usersModel->expects($this->once())
            ->method('setData')
            ->with(['type' => 'admin_activity', 'action' => $testAction])
            ->willReturnSelf();
        $this->usersModel->expects($this->once())
            ->method('save');

        $this->model->execute($eventObserver);
    }
}
