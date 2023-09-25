<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\NewRelicReporting\Test\Unit\Model\Observer;

use Magento\NewRelicReporting\Model\Observer\ReportConcurrentUsers;

/**
 * Class ReportConcurrentUsersTest
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ReportConcurrentUsersTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var ReportConcurrentUsers
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
        $this->customerSession = $this->getMockBuilder(\Magento\Customer\Model\Session::class)
            ->disableOriginalConstructor()
            ->setMethods(['isLoggedIn', 'getCustomerId'])
            ->getMock();
        $this->customerRepository = $this->getMockBuilder(\Magento\Customer\Api\CustomerRepositoryInterface::class)
            ->getMock();
        $this->storeManager = $this->getMockBuilder(\Magento\Store\Model\StoreManagerInterface::class)
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

        $this->model = new ReportConcurrentUsers(
            $this->config,
            $this->customerSession,
            $this->customerRepository,
            $this->storeManager,
            $this->usersFactory,
            $this->jsonEncoder
        );
    }

    /**
     * Test case when module is disabled in config
     *
     * @return void
     */
    public function testReportConcurrentUsersModuleDisabledFromConfig()
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
    public function testReportConcurrentUsersUserIsNotLoggedIn()
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

        $this->model->execute($eventObserver);
    }

    /**
     * Test case when module is enabled and user is logged in
     *
     * @return void
     */
    public function testReportConcurrentUsers()
    {
        $testCustomerId = 1;
        $testAction = 'JSON string';

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
        $this->jsonEncoder->expects($this->once())
            ->method('encode')
            ->willReturn($testAction);
        $this->usersModel->expects($this->once())
            ->method('setData')
            ->with(['type' => 'user_action', 'action' => $testAction])
            ->willReturnSelf();
        $this->usersModel->expects($this->once())
            ->method('save');

        $this->model->execute($eventObserver);
    }
}
