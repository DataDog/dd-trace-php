<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\NewRelicReporting\Test\Unit\Model\Observer;

use Magento\NewRelicReporting\Model\Observer\ReportConcurrentUsersToNewRelic;

/**
 * Class ReportConcurrentUsersToNewRelicTest
 */
class ReportConcurrentUsersToNewRelicTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var ReportConcurrentUsersToNewRelic
     */
    protected $model;

    /**
     * @var \Magento\NewRelicReporting\Model\Config|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $config;

    /**
     * @var \Magento\Customer\Model\Session|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $customerSession;

    /**
     * @var \Magento\Customer\Api\CustomerRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $customerRepository;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $storeManager;

    /**
     * @var \Magento\NewRelicReporting\Model\NewRelicWrapper|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $newRelicWrapper;

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
        $this->customerSession = $this->getMockBuilder(\Magento\Customer\Model\Session::class)
            ->disableOriginalConstructor()
            ->setMethods(['isLoggedIn', 'getCustomerId'])
            ->getMock();
        $this->customerRepository = $this->getMockBuilder(\Magento\Customer\Api\CustomerRepositoryInterface::class)
            ->getMock();
        $this->storeManager = $this->getMockBuilder(\Magento\Store\Model\StoreManagerInterface::class)
            ->getMock();
        $this->newRelicWrapper = $this->getMockBuilder(\Magento\NewRelicReporting\Model\NewRelicWrapper::class)
            ->disableOriginalConstructor()
            ->setMethods(['addCustomParameter'])
            ->getMock();

        $this->model = new ReportConcurrentUsersToNewRelic(
            $this->config,
            $this->customerSession,
            $this->customerRepository,
            $this->storeManager,
            $this->newRelicWrapper
        );
    }

    /**
     * Test case when module is disabled in config
     *
     * @return void
     */
    public function testReportConcurrentUsersToNewRelicModuleDisabledFromConfig()
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
    public function testReportConcurrentUsersToNewRelicUserIsNotLoggedIn()
    {
        /** @var \Magento\Framework\Event\Observer|\PHPUnit\Framework\MockObject\MockObject $eventObserver */
        $eventObserver = $this->getMockBuilder(\Magento\Framework\Event\Observer::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->config->expects($this->once())
            ->method('isNewRelicEnabled')
            ->willReturn(true);
        $this->customerSession->expects($this->once())
            ->method('isLoggedIn')
            ->willReturn(false);
        $storeMock = $this->getMockBuilder(\Magento\Store\Model\Store::class)->disableOriginalConstructor()->getMock();
        $this->storeManager->expects($this->once())
            ->method('getStore')
            ->willReturn($storeMock);
        $websiteMock = $this->getMockBuilder(
            \Magento\Store\Model\Website::class
        )->disableOriginalConstructor()->getMock();
        $this->storeManager->expects($this->once())
            ->method('getWebsite')
            ->willReturn($websiteMock);
        $this->newRelicWrapper->expects($this->exactly(2))
            ->method('addCustomParameter')
            ->willReturn(true);

        $this->model->execute($eventObserver);
    }

    /**
     * Test case when module is enabled and user is logged in
     *
     * @return void
     */
    public function testReportConcurrentUsersToNewRelic()
    {
        $testCustomerId = 1;

        /** @var \Magento\Framework\Event\Observer|\PHPUnit\Framework\MockObject\MockObject $eventObserver */
        $eventObserver = $this->getMockBuilder(\Magento\Framework\Event\Observer::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->config->expects($this->once())
            ->method('isNewRelicEnabled')
            ->willReturn(true);
        $this->customerSession->expects($this->once())
            ->method('isLoggedIn')
            ->willReturn(true);
        $this->customerSession->expects($this->once())
            ->method('getCustomerId')
            ->willReturn($testCustomerId);
        $customerMock = $this->getMockBuilder(\Magento\Customer\Api\Data\CustomerInterface::class)->getMock();
        $this->customerRepository->expects($this->once())
            ->method('getById')
            ->willReturn($customerMock);
        $storeMock = $this->getMockBuilder(\Magento\Store\Model\Store::class)->disableOriginalConstructor()->getMock();
        $this->storeManager->expects($this->once())
            ->method('getStore')
            ->willReturn($storeMock);
        $websiteMock = $this->getMockBuilder(
            \Magento\Store\Model\Website::class
        )->disableOriginalConstructor()->getMock();
        $this->storeManager->expects($this->once())
            ->method('getWebsite')
            ->willReturn($websiteMock);
        $this->newRelicWrapper->expects($this->exactly(4))
            ->method('addCustomParameter')
            ->willReturn(true);

        $this->model->execute($eventObserver);
    }
}
