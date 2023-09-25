<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Reports\Test\Unit\Observer;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CatalogProductCompareAddProductObserverTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Reports\Observer\CatalogProductCompareAddProductObserver
     */
    protected $observer;

    /**
     * @var \Magento\Reports\Observer\EventSaver|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $eventSaverMock;

    /**
     * @var \Magento\Customer\Model\Session|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $customerSessionMock;

    /**
     * @var \Magento\Customer\Model\Visitor|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $customerVisitorMock;

    /**
     * @var \Magento\Reports\Model\Event|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $reportEventMock;

    /**
     * @var \Magento\Store\Model\Store|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $storeMock;

    /**
     * @var \Magento\Reports\Model\Product\Index\ComparedFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $productCompFactoryMock;

    /**
     * @var \Magento\Reports\Model\Product\Index\Compared|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $productCompModelMock;

    /**
     * @var \Magento\Reports\Model\ReportStatus|\PHPUnit\Framework\MockObject\MockObject
     */
    private $reportStatusMock;

    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);

        $this->customerSessionMock = $this->getMockBuilder(\Magento\Customer\Model\Session::class)
            ->disableOriginalConstructor()->getMock();
        $this->customerVisitorMock = $this->getMockBuilder(\Magento\Customer\Model\Visitor::class)
            ->disableOriginalConstructor()->getMock();

        $reportEventFactory = $this->getMockBuilder(\Magento\Reports\Model\EventFactory::class)
            ->setMethods(['create'])->disableOriginalConstructor()->getMock();
        $this->reportEventMock = $this->getMockBuilder(\Magento\Reports\Model\Event::class)
            ->disableOriginalConstructor()->getMock();

        $reportEventFactory->expects($this->any())
            ->method('create')
            ->willReturn($this->reportEventMock);

        /** @var \Magento\Store\Model\StoreManagerInterface|\PHPUnit\Framework\MockObject\MockObject $storeManager */
        $storeManager = $this->createMock(\Magento\Store\Model\StoreManagerInterface::class);
        $this->storeMock = $this->getMockBuilder(\Magento\Store\Model\Store::class)
            ->disableOriginalConstructor()->getMock();

        $storeManager->expects($this->any())
            ->method('getStore')
            ->willReturn($this->storeMock);

        $this->productCompModelMock = $this->getMockBuilder(\Magento\Reports\Model\Product\Index\Compared::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->productCompFactoryMock = $this->getMockBuilder(
            \Magento\Reports\Model\Product\Index\ComparedFactory::class
        )->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();
        $this->productCompFactoryMock->expects($this->any())
            ->method('create')
            ->willReturn($this->productCompModelMock);

        $this->eventSaverMock = $this->getMockBuilder(\Magento\Reports\Observer\EventSaver::class)
            ->disableOriginalConstructor()
            ->setMethods(['save'])
            ->getMock();

        $this->reportStatusMock = $this->getMockBuilder(\Magento\Reports\Model\ReportStatus::class)
            ->disableOriginalConstructor()
            ->setMethods(['isReportEnabled'])
            ->getMock();

        $this->observer = $objectManager->getObject(
            \Magento\Reports\Observer\CatalogProductCompareAddProductObserver::class,
            [
                'productCompFactory' => $this->productCompFactoryMock,
                'customerSession' => $this->customerSessionMock,
                'customerVisitor' => $this->customerVisitorMock,
                'eventSaver' => $this->eventSaverMock,
                'reportStatus' => $this->reportStatusMock
            ]
        );
    }

    /**
     * @param bool $isLoggedIn
     * @param string $userKey
     * @param int $userId
     * @dataProvider catalogProductCompareAddProductDataProvider
     * @return void
     */
    public function testCatalogProductCompareAddProduct($isLoggedIn, $userKey, $userId)
    {
        $productId = 111;
        $customerId = 222;
        $visitorId = 333;
        $viewData = [
            'product_id' => $productId,
            $userKey => $userId
        ];
        $observerMock = $this->getObserverMock($productId);

        $this->reportStatusMock->expects($this->once())->method('isReportEnabled')->willReturn(true);
        $this->customerSessionMock->expects($this->any())->method('isLoggedIn')->willReturn($isLoggedIn);
        $this->customerSessionMock->expects($this->any())->method('getCustomerId')->willReturn($customerId);

        $this->customerVisitorMock->expects($this->any())->method('getId')->willReturn($visitorId);

        $this->productCompModelMock->expects($this->any())->method('setData')->with($viewData)->willReturnSelf();
        $this->productCompModelMock->expects($this->any())->method('save')->willReturnSelf();
        $this->productCompModelMock->expects($this->any())->method('calculate')->willReturnSelf();

        $this->eventSaverMock->expects($this->once())->method('save');

        $this->observer->execute($observerMock);
    }

    /**
     * @return array
     */
    public function catalogProductCompareAddProductDataProvider()
    {
        return [
            'logged in' => [
                'isLoggedIn' => true,
                'userKey' => 'customer_id',
                'userId' => 222
            ],
            'not logged in' => [
                'isLoggedIn' => false,
                'userKey' => 'visitor_id',
                'userId' => 333
            ]
        ];
    }

    /**
     * @param int $productId
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    protected function getObserverMock($productId)
    {
        $eventObserverMock = $this->getMockBuilder(\Magento\Framework\Event\Observer::class)
            ->disableOriginalConstructor()
            ->getMock();
        $eventMock = $this->getMockBuilder(\Magento\Framework\Event::class)
            ->disableOriginalConstructor()
            ->setMethods(['getProduct'])->getMock();
        $productMock = $this->getMockBuilder(\Magento\Catalog\Model\Product::class)
            ->disableOriginalConstructor()
            ->getMock();

        $productMock->expects($this->any())->method('getId')->willReturn($productId);

        $eventMock->expects($this->any())->method('getProduct')->willReturn($productMock);

        $eventObserverMock->expects($this->any())->method('getEvent')->willReturn($eventMock);

        return $eventObserverMock;
    }
}
