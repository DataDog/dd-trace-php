<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Signifyd\Test\Unit\Model;

use Magento\Directory\Model\Currency;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Model\Order;
use Magento\Signifyd\Model\CustomerOrders;
use PHPUnit\Framework\MockObject\MockObject as MockObject;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CustomerOrdersTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var int
     */
    private static $customerId = 1;

    /**
     * Order amount in EUR
     * @var int
     */
    private static $eurAmount = 100;

    /**
     * Order amount in UAH
     * @var int
     */
    private static $uahAmount = 270;

    /**
     * Order amount in USD
     * @var int
     */
    private static $usdAmount = 50;

    /**
     * @var ObjectManager
     */
    private $objectManager;

    /**
     * @var FilterBuilder|MockObject
     */
    private $filterBuilder;

    /**
     * @var Currency|MockObject
     */
    private $eurCurrency;

    /**
     * @var Currency|MockObject
     */
    private $uahCurrency;

    /**
     * @var CustomerOrders
     */
    private $model;

    /**
     * @var OrderRepositoryInterface|MockObject
     */
    private $orderRepository;

    /**
     * @var SearchCriteriaBuilder|MockObject
     */
    private $searchCriteriaBuilder;

    /**
     * @var LoggerInterface|MockObject
     */
    private $logger;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->objectManager = new ObjectManager($this);

        $this->orderRepository = $this->getMockBuilder(OrderRepositoryInterface::class)
             ->getMockForAbstractClass();

        $this->logger = $this->getMockBuilder(LoggerInterface::class)
            ->getMockForAbstractClass();

        $this->filterBuilder = $this->getMockBuilder(FilterBuilder::class)
                ->disableOriginalConstructor()
                ->getMock();

        $this->searchCriteriaBuilder = $this->getMockBuilder(SearchCriteriaBuilder::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->model = $this->objectManager->getObject(CustomerOrders::class, [
            'filterBuilder' => $this->filterBuilder,
            'orderRepository' => $this->orderRepository,
            'searchCriteriaBuilder' => $this->searchCriteriaBuilder,
            'logger' => $this->logger
        ]);

        $this->initCurrencies();
        $this->initOrderRepository();

        $this->objectManager->setBackwardCompatibleProperty(
            $this->model,
            'currencies',
            ['EUR' => $this->eurCurrency, 'UAH' => $this->uahCurrency]
        );
    }

    /**
     * @covers \Magento\Signifyd\Model\CustomerOrders::getAggregatedOrdersInfo()
     */
    public function testGetCountAndTotalAmount()
    {
        $this->eurCurrency->expects($this->once())
            ->method('convert')
            ->with(self::$eurAmount, 'USD')
            ->willReturn(109);

        $this->uahCurrency->expects($this->once())
            ->method('convert')
            ->with(self::$uahAmount, 'USD')
            ->willReturn(10.35);

        $actual = $this->model->getAggregatedOrdersInfo(self::$customerId);

        static::assertEquals(3, $actual['aggregateOrderCount']);
        static::assertEquals(169.35, $actual['aggregateOrderDollars']);
    }

    /**
     * Test case when required currency rate is absent and exception is thrown
     * @covers \Magento\Signifyd\Model\CustomerOrders::getAggregatedOrdersInfo()
     */
    public function testGetCountAndTotalAmountNegative()
    {
        $this->eurCurrency->expects($this->once())
            ->method('convert')
            ->with(self::$eurAmount, 'USD')
            ->willReturn(109);

        $this->uahCurrency->expects($this->once())
            ->method('convert')
            ->with(self::$uahAmount, 'USD')
            ->willThrowException(new \Exception());

        $this->logger->expects($this->once())
            ->method('error');

        $actual = $this->model->getAggregatedOrdersInfo(self::$customerId);

        $this->assertNull($actual['aggregateOrderCount']);
        $this->assertNull($actual['aggregateOrderDollars']);
    }

    /**
     * Populate order repository with mocked orders
     */
    private function initOrderRepository()
    {
        $this->filterBuilder->expects($this->once())
            ->method('setField')
            ->willReturnSelf();
        $this->filterBuilder->expects($this->once())
            ->method('setValue')
            ->willReturnSelf();
        $filter = $this->getMockBuilder(\Magento\Framework\Api\Filter::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->filterBuilder->expects($this->once())
            ->method('create')
            ->willReturn($filter);

        $searchCriteria = $this->getMockBuilder(\Magento\Framework\Api\SearchCriteria::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->searchCriteriaBuilder->expects($this->once())
            ->method('create')
            ->willReturn($searchCriteria);

        $orderSearchResult = $this->getMockBuilder(OrderSearchResultInterface::class)
            ->getMockForAbstractClass();
        $orderSearchResult->expects($this->once())
            ->method('getItems')
            ->willReturn($this->getOrders());
        $this->orderRepository->expects($this->once())
            ->method('getList')
            ->willReturn($orderSearchResult);
    }

    /**
     * Creates mocks for currencies
     * @return void
     */
    private function initCurrencies()
    {
        $this->eurCurrency = $this->getMockBuilder(Currency::class)
            ->disableOriginalConstructor()
            ->setMethods(['convert'])
            ->getMock();

        $this->uahCurrency = $this->getMockBuilder(Currency::class)
            ->disableOriginalConstructor()
            ->setMethods(['convert'])
            ->getMock();
    }

    /**
     * Get list of mocked orders with different currencies
     * @return array
     */
    private function getOrders()
    {
        $eurOrder = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->setMethods(['getBaseGrandTotal', 'getBaseCurrencyCode'])
            ->getMock();

        $eurOrder->expects($this->once())
            ->method('getBaseGrandTotal')
            ->willReturn(self::$eurAmount);
        $eurOrder->expects($this->once())
            ->method('getBaseCurrencyCode')
            ->willReturn('EUR');

        $uahOrder = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->setMethods(['getBaseGrandTotal', 'getBaseCurrencyCode'])
            ->getMock();

        $uahOrder->expects($this->once())
            ->method('getBaseGrandTotal')
            ->willReturn(self::$uahAmount);
        $uahOrder->expects($this->once())
            ->method('getBaseCurrencyCode')
            ->willReturn('UAH');

        $usdOrder = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->setMethods(['getBaseGrandTotal', 'getBaseCurrencyCode'])
            ->getMock();

        $usdOrder->expects($this->once())
            ->method('getBaseGrandTotal')
            ->willReturn(self::$usdAmount);
        $usdOrder->expects($this->once())
            ->method('getBaseCurrencyCode')
            ->willReturn('USD');

        return [$usdOrder, $eurOrder, $uahOrder];
    }
}
