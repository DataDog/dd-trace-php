<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Reports\Test\Unit\Model\ResourceModel\Order;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactory;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Helper;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\VersionControl\Snapshot;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Reports\Model\ResourceModel\Order\Collection;
use Magento\Sales\Model\Order\Config;
use Magento\Sales\Model\ResourceModel\Report\Order;
use Magento\Sales\Model\ResourceModel\Report\OrderFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\Matcher\InvokedCount;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CollectionTest extends TestCase
{
    /**
     * @var Collection
     */
    protected $collection;

    /**
     * @var EntityFactory|MockObject
     */
    protected $entityFactoryMock;

    /**
     * @var LoggerInterface|MockObject
     */
    protected $loggerMock;

    /**
     * @var FetchStrategyInterface|MockObject
     */
    protected $fetchStrategyMock;

    /**
     * @var ManagerInterface|MockObject
     */
    protected $managerMock;

    /**
     * @var \Magento\Sales\Model\ResourceModel\EntitySnapshot|MockObject
     */
    protected $entitySnapshotMock;

    /**
     * @var Helper|MockObject
     */
    protected $helperMock;

    /**
     * @var ScopeConfigInterface|MockObject
     */
    protected $scopeConfigMock;

    /**
     * @var StoreManagerInterface|MockObject
     */
    protected $storeManagerMock;

    /**
     * @var TimezoneInterface|MockObject
     */
    protected $timezoneMock;

    /**
     * @var Config|MockObject
     */
    protected $configMock;

    /**
     * @var OrderFactory|MockObject
     */
    protected $orderFactoryMock;

    /**
     * @var AdapterInterface|MockObject
     */
    protected $connectionMock;

    /**
     * @var Select|MockObject
     */
    protected $selectMock;

    /**
     * @var AbstractDb|MockObject
     */
    protected $resourceMock;

    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        $this->entityFactoryMock = $this->getMockBuilder(EntityFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->loggerMock = $this->getMockBuilder(LoggerInterface::class)
            ->getMock();
        $this->fetchStrategyMock = $this->getMockBuilder(
            FetchStrategyInterface::class
        )->getMock();
        $this->managerMock = $this->getMockBuilder(ManagerInterface::class)
            ->getMock();
        $snapshotClassName = Snapshot::class;
        $this->entitySnapshotMock = $this->getMockBuilder($snapshotClassName)
            ->disableOriginalConstructor()
            ->getMock();
        $this->helperMock = $this->getMockBuilder(Helper::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->scopeConfigMock = $this->getMockBuilder(ScopeConfigInterface::class)
            ->getMock();
        $this->storeManagerMock = $this->getMockBuilder(StoreManagerInterface::class)
            ->getMock();
        $this->timezoneMock = $this->getMockBuilder(TimezoneInterface::class)
            ->getMock();
        $this->timezoneMock
            ->expects($this->any())
            ->method('getConfigTimezone')
            ->willReturn('America/Chicago');
        $this->configMock = $this->getMockBuilder(Config::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->orderFactoryMock = $this->getMockBuilder(OrderFactory::class)
            ->onlyMethods(['create'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->selectMock = $this->getMockBuilder(Select::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->selectMock
            ->expects($this->any())
            ->method('columns')
            ->willReturnSelf();
        $this->selectMock
            ->expects($this->any())
            ->method('where')
            ->willReturnSelf();
        $this->selectMock
            ->expects($this->any())
            ->method('order')
            ->willReturnSelf();
        $this->selectMock
            ->expects($this->any())
            ->method('group')
            ->willReturnSelf();
        $this->selectMock
            ->expects($this->any())
            ->method('getPart')
            ->willReturn([]);

        $this->connectionMock = $this->getMockBuilder(Mysql::class)
            ->onlyMethods(['select', 'getIfNullSql', 'getDateFormatSql', 'prepareSqlCondition', 'getCheckSql'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->connectionMock
            ->expects($this->any())
            ->method('select')
            ->willReturn($this->selectMock);

        $this->resourceMock = $this->getMockBuilder(AbstractDb::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->resourceMock
            ->expects($this->once())
            ->method('getConnection')
            ->willReturn($this->connectionMock);

        $this->collection = new Collection(
            $this->entityFactoryMock,
            $this->loggerMock,
            $this->fetchStrategyMock,
            $this->managerMock,
            $this->entitySnapshotMock,
            $this->helperMock,
            $this->scopeConfigMock,
            $this->storeManagerMock,
            $this->timezoneMock,
            $this->configMock,
            $this->orderFactoryMock,
            null,
            $this->resourceMock
        );
    }

    /**
     * @return void
     */
    public function testCheckIsLive(): void
    {
        $range = '';
        $this->scopeConfigMock
            ->expects($this->once())
            ->method('getValue')
            ->with(
                'sales/dashboard/use_aggregated_data',
                ScopeInterface::SCOPE_STORE
            );

        $this->collection->checkIsLive($range);
    }

    /**
     * @param int $useAggregatedData
     * @param string $mainTable
     * @param int $isFilter
     * @param InvokedCount $getIfNullSqlResult
     *
     * @return void
     * @dataProvider useAggregatedDataDataProvider
     */
    public function testPrepareSummary($useAggregatedData, $mainTable, $isFilter, $getIfNullSqlResult): void
    {
        $range = '';
        $customStart = 1;
        $customEnd = 10;

        $this->scopeConfigMock
            ->expects($this->once())
            ->method('getValue')
            ->with(
                'sales/dashboard/use_aggregated_data',
                ScopeInterface::SCOPE_STORE
            )
            ->willReturn($useAggregatedData);

        $orderMock = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->getMock();
        $orderMock->method('getStoreTZOffsetQuery')->willReturn('');

        $this->orderFactoryMock
            ->expects($this->any())
            ->method('create')
            ->willReturn($orderMock);

        $this->resourceMock
            ->method('getTable')
            ->withConsecutive([$mainTable]);

        $this->connectionMock
            ->expects($getIfNullSqlResult)
            ->method('getIfNullSql');

        $this->connectionMock->expects($this->once())
            ->method('getDateFormatSql')
            ->with('{{attribute}}', '%Y-%m')
            ->willReturn(new \Zend_Db_Expr('DATE_FORMAT(%2021-%10, %Y-%m)'));

        $this->collection->prepareSummary($range, $customStart, $customEnd, $isFilter);
    }

    /**
     * @param int $range
     * @param string $customStart
     * @param string $customEnd
     * @param string $expectedInterval
     *
     * @return void
     * @dataProvider firstPartDateRangeDataProvider
     */
    public function testGetDateRangeFirstPart($range, $customStart, $customEnd, $expectedInterval): void
    {
        $timeZoneToReturn = date_default_timezone_get();
        date_default_timezone_set('UTC');
        $result = $this->collection->getDateRange($range, $customStart, $customEnd);
        $interval = $result['to']->diff($result['from']);
        date_default_timezone_set($timeZoneToReturn);
        $intervalResult = $interval->format('%y %m %d %h:%i:%s');
        $this->assertEquals($expectedInterval, $intervalResult);
    }

    /**
     * @param int $range
     * @param string $customStart
     * @param string $customEnd
     * @param string $config
     * @param int $expectedYear
     * @dataProvider secondPartDateRangeDataProvider
     * @return void
     */
    public function testGetDateRangeSecondPart($range, $customStart, $customEnd, $config, $expectedYear): void
    {
        $this->scopeConfigMock
            ->expects($this->once())
            ->method('getValue')
            ->with(
                $config,
                ScopeInterface::SCOPE_STORE
            )
            ->willReturn(1);

        $result = $this->collection->getDateRange($range, $customStart, $customEnd);
        $this->assertCount(3, $result);
        $resultStartDate = $result['from'];
        $this->assertEquals($expectedYear, $resultStartDate->format('Y'));
    }

    /**
     * @return void
     */
    public function testGetDateRangeWithReturnObject(): void
    {
        $this->assertCount(2, $this->collection->getDateRange('7d', '', '', true));
        $this->assertCount(3, $this->collection->getDateRange('7d', '', '', false));
    }

    /**
     * @return void
     */
    public function testAddItemCountExpr(): void
    {
        $this->selectMock
            ->expects($this->once())
            ->method('columns')
            ->with(['items_count' => 'total_item_count'], 'main_table');
        $this->collection->addItemCountExpr();
    }

    /**
     * @param int $isFilter
     * @param int $useAggregatedData
     * @param string $mainTable
     * @param InvokedCount $getIfNullSqlResult
     *
     * @return void
     * @dataProvider totalsDataProvider
     */
    public function testCalculateTotals($isFilter, $useAggregatedData, $mainTable, $getIfNullSqlResult): void
    {
        $this->scopeConfigMock
            ->expects($this->once())
            ->method('getValue')
            ->with(
                'sales/dashboard/use_aggregated_data',
                ScopeInterface::SCOPE_STORE
            )
            ->willReturn($useAggregatedData);

        $this->resourceMock
            ->method('getTable')
            ->with($mainTable);

        $this->connectionMock
            ->expects($getIfNullSqlResult)
            ->method('getIfNullSql');

        $this->collection->checkIsLive('');
        $this->collection->calculateTotals($isFilter);
    }

    /**
     * @param int $isFilter
     * @param string $useAggregatedData
     * @param string $mainTable
     *
     * @return void
     * @dataProvider salesDataProvider
     */
    public function testCalculateSales($isFilter, $useAggregatedData, $mainTable): void
    {
        $this->scopeConfigMock
            ->expects($this->once())
            ->method('getValue')
            ->with(
                'sales/dashboard/use_aggregated_data',
                ScopeInterface::SCOPE_STORE
            )
            ->willReturn($useAggregatedData);

        $storeMock = $this->getMockBuilder(Store::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->storeManagerMock
            ->expects($this->any())
            ->method('getStore')
            ->willReturn($storeMock);

        $this->resourceMock
            ->method('getTable')
            ->with($mainTable);

        $this->collection->calculateSales($isFilter);
    }

    /**
     * @return void
     */
    public function testSetDateRange(): void
    {
        $fromDate = '1';
        $toDate = '2';

        $this->connectionMock
            ->method('prepareSqlCondition')
            ->withConsecutive(['`created_at`', ['from' => $fromDate, 'to' => $toDate]]);

        $this->collection->setDateRange($fromDate, $toDate);
    }

    /**
     * @param array $storeIds
     * @param array $parameters
     *
     * @return void
     * @dataProvider storesDataProvider
     */
    public function testSetStoreIds($storeIds, $parameters): void
    {
        $this->connectionMock
            ->expects($this->any())
            ->method('getIfNullSql')
            ->willReturn('text');

        $this->selectMock
            ->expects($this->once())
            ->method('columns')
            ->with($parameters)
            ->willReturnSelf();

        $this->collection->setStoreIds($storeIds);
    }

    /**
     * @return array
     */
    public function useAggregatedDataDataProvider(): array
    {
        return [
            [1, 'sales_order_aggregated_created', 0, $this->never()],
            [0, 'sales_order', 0, $this->exactly(7)],
            [0, 'sales_order', 1, $this->exactly(6)]
        ];
    }

    /**
     * @return array
     */
    public function firstPartDateRangeDataProvider(): array
    {
        return [
            ['', '', '', '0 0 0 23:59:59'],
            ['24h', '', '', '0 0 1 0:0:0'],
            ['7d', '', '', '0 0 6 23:59:59']
        ];
    }

    /**
     * @return array
     */
    public function secondPartDateRangeDataProvider(): array
    {
        $dateStart = new \DateTime();
        $expectedYear = $dateStart->format('Y');
        $expected2YTDYear = $expectedYear - 1;

        return [
            ['1m', 1, 10, 'reports/dashboard/mtd_start', $expectedYear],
            ['1y', 1, 10, 'reports/dashboard/ytd_start', $expectedYear],
            ['2y', 1, 10, 'reports/dashboard/ytd_start', $expected2YTDYear]
        ];
    }

    /**
     * @return array
     */
    public function totalsDataProvider(): array
    {
        return [
            [1, 1, 'sales_order_aggregated_created', $this->never()],
            [0, 1, 'sales_order_aggregated_created', $this->never()],
            [1, 0, 'sales_order', $this->exactly(10)],
            [0, 0, 'sales_order', $this->exactly(11)]
        ];
    }

    /**
     * @return array
     */
    public function salesDataProvider(): array
    {
        return [
            [1, 1, 'sales_order_aggregated_created'],
            [0, 1, 'sales_order_aggregated_created'],
            [1, 0, 'sales_order'],
            [0, 0, 'sales_order']
        ];
    }

    /**
     * @return array
     */
    public function storesDataProvider(): array
    {
        $firstReturn = [
            'subtotal' => 'SUM(main_table.base_subtotal * main_table.base_to_global_rate)',
            'tax' => 'SUM(main_table.base_tax_amount * main_table.base_to_global_rate)',
            'shipping' => 'SUM(main_table.base_shipping_amount * main_table.base_to_global_rate)',
            'discount' => 'SUM(main_table.base_discount_amount * main_table.base_to_global_rate)',
            'total' => 'SUM(main_table.base_grand_total * main_table.base_to_global_rate)',
            'invoiced' => 'SUM(main_table.base_total_paid * main_table.base_to_global_rate)',
            'refunded' => 'SUM(main_table.base_total_refunded * main_table.base_to_global_rate)',
            'profit' => 'SUM(text *  main_table.base_to_global_rate) + SUM(text * main_table.base_to_global_rate) ' .
                '- SUM(text * main_table.base_to_global_rate) - SUM(text * main_table.base_to_global_rate) ' .
                '- SUM(text * main_table.base_to_global_rate)',
        ];

        $secondReturn = [
            'subtotal' => 'SUM(main_table.base_subtotal)',
            'tax' => 'SUM(main_table.base_tax_amount)',
            'shipping' => 'SUM(main_table.base_shipping_amount)',
            'discount' => 'SUM(main_table.base_discount_amount)',
            'total' => 'SUM(main_table.base_grand_total)',
            'invoiced' => 'SUM(main_table.base_total_paid)',
            'refunded' => 'SUM(main_table.base_total_refunded)',
            'profit' => 'SUM(text) + SUM(text) - SUM(text) - SUM(text) - SUM(text)'
        ];

        return [
            [[], $firstReturn],
            [[1], $secondReturn]
        ];
    }
}
