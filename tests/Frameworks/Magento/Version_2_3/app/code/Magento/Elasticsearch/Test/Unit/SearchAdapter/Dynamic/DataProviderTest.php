<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Elasticsearch\Test\Unit\SearchAdapter\Dynamic;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class DataProviderTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Elasticsearch\SearchAdapter\QueryContainer|\PHPUnit\Framework\MockObject\MockObject
     */
    private $queryContainer;

    /**
     * @var \Magento\Elasticsearch\SearchAdapter\Dynamic\DataProvider
     */
    protected $model;

    /**
     * @var \Magento\Elasticsearch\SearchAdapter\ConnectionManager|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $connectionManager;

    /**
     * @var \Magento\Elasticsearch\Model\Adapter\FieldMapperInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $fieldMapper;

    /**
     * @var \Magento\Catalog\Model\Layer\Filter\Price\Range|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $range;

    /**
     * @var \Magento\Framework\Search\Dynamic\IntervalFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $intervalFactory;

    /**
     * @var \Magento\Elasticsearch\Model\Config|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $clientConfig;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $storeManager;

    /**
     * @var \Magento\Customer\Model\Session|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $customerSession;

    /**
     * @var \Magento\Framework\Search\Dynamic\EntityStorage|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $entityStorage;

    /**
     * @var \Magento\Store\Api\Data\StoreInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $storeMock;

    /**
     * @var \Magento\Elasticsearch\Model\Client\Elasticsearch|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $clientMock;

    /**
     * @var \Magento\Elasticsearch\SearchAdapter\SearchIndexNameResolver|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $searchIndexNameResolver;

    /**
     * @var \Magento\Framework\App\ScopeResolverInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $scopeResolver;

    /**
     * @var \Magento\Framework\App\ScopeInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $scopeInterface;

    /**
     * A private helper for setUp method.
     * @return void
     */
    private function setUpMockObjects()
    {
        $this->connectionManager = $this->getMockBuilder(\Magento\Elasticsearch\SearchAdapter\ConnectionManager::class)
            ->setMethods(['getConnection'])
            ->disableOriginalConstructor()
            ->getMock();

        $this->range = $this->getMockBuilder(\Magento\Catalog\Model\Layer\Filter\Price\Range::class)
            ->setMethods(['getPriceRange'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->intervalFactory = $this->getMockBuilder(\Magento\Framework\Search\Dynamic\IntervalFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->clientConfig = $this->getMockBuilder(\Magento\Elasticsearch\Model\Config::class)
            ->setMethods([
                'getIndexName',
                'getEntityType',
            ])
            ->disableOriginalConstructor()
            ->getMock();
        $this->storeManager = $this->getMockBuilder(\Magento\Store\Model\StoreManagerInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->customerSession = $this->getMockBuilder(\Magento\Customer\Model\Session::class)
            ->setMethods(['getCustomerGroupId'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->entityStorage = $this->getMockBuilder(\Magento\Framework\Search\Dynamic\EntityStorage::class)
            ->setMethods(['getSource'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->entityStorage->expects($this->any())
            ->method('getSource')
            ->willReturn([1]);
        $this->customerSession->expects($this->any())
            ->method('getCustomerGroupId')
            ->willReturn(1);
        $this->storeMock = $this->getMockBuilder(\Magento\Store\Api\Data\StoreInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->storeManager->expects($this->any())
            ->method('getStore')
            ->willReturn($this->storeMock);
        $this->storeMock->expects($this->any())
            ->method('getWebsiteId')
            ->willReturn(1);
        $this->storeMock->expects($this->any())
            ->method('getId')
            ->willReturn(1);
        $this->clientConfig->expects($this->any())
            ->method('getIndexName')
            ->willReturn('indexName');
        $this->clientConfig->expects($this->any())
            ->method('getEntityType')
            ->willReturn('product');
        $this->clientMock = $this->getMockBuilder(\Magento\Elasticsearch\Model\Client\Elasticsearch::class)
            ->setMethods(['query'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->connectionManager->expects($this->any())
            ->method('getConnection')
            ->willReturn($this->clientMock);

        $this->fieldMapper = $this->getMockBuilder(\Magento\Elasticsearch\Model\Adapter\FieldMapperInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->searchIndexNameResolver = $this
            ->getMockBuilder(\Magento\Elasticsearch\SearchAdapter\SearchIndexNameResolver::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->scopeResolver = $this->getMockForAbstractClass(
            \Magento\Framework\App\ScopeResolverInterface::class,
            [],
            '',
            false
        );

        $this->scopeInterface = $this->getMockForAbstractClass(
            \Magento\Framework\App\ScopeInterface::class,
            [],
            '',
            false
        );

        $this->queryContainer = $this->getMockBuilder(\Magento\Elasticsearch\SearchAdapter\QueryContainer::class)
            ->disableOriginalConstructor()
            ->setMethods(['getQuery'])
            ->getMock();
    }

    /**
     * Setup method
     * @return void
     */
    protected function setUp(): void
    {
        $this->setUpMockObjects();

        $objectManagerHelper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->model = $objectManagerHelper->getObject(
            \Magento\Elasticsearch\SearchAdapter\Dynamic\DataProvider::class,
            [
                'connectionManager' => $this->connectionManager,
                'fieldMapper' => $this->fieldMapper,
                'range' => $this->range,
                'intervalFactory' => $this->intervalFactory,
                'clientConfig' => $this->clientConfig,
                'storeManager' => $this->storeManager,
                'customerSession' => $this->customerSession,
                'searchIndexNameResolver' => $this->searchIndexNameResolver,
                'indexerId' => 'catalogsearch_fulltext',
                'scopeResolver' => $this->scopeResolver,
                'queryContainer' => $this->queryContainer,
            ]
        );
    }

    /**
     * Test getRange() method
     */
    public function testGetRange()
    {
        $this->range->expects($this->once())
            ->method('getPriceRange')
            ->willReturn([]);
        $this->assertEquals(
            [],
            $this->model->getRange()
        );
    }

    /**
     * Test getAggregations() method
     */
    public function testGetAggregations()
    {
        $expectedResult = [
            'count' => 1,
            'max' => 1,
            'min' => 1,
            'std' => 1,
        ];
        $this->clientMock->expects($this->once())
            ->method('query')
            ->willReturn([
                'aggregations' => [
                    'prices' => [
                        'count' => 1,
                        'max' => 1,
                        'min' => 1,
                        'std_deviation' => 1,
                    ],
                ],
            ]);

        $this->queryContainer->expects($this->once())
            ->method('getQuery')
            ->willReturn([]);

        $this->assertEquals(
            $expectedResult,
            $this->model->getAggregations($this->entityStorage)
        );
    }

    /**
     * Test getInterval() method
     */
    public function testGetInterval()
    {
        $dimensionValue = 1;
        $bucket = $this->getMockBuilder(\Magento\Framework\Search\Request\BucketInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $interval = $this->getMockBuilder(\Magento\Framework\Search\Dynamic\IntervalInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $dimension = $this->getMockBuilder(\Magento\Framework\Search\Request\Dimension::class)
            ->setMethods(['getValue'])
            ->disableOriginalConstructor()
            ->getMock();
        $dimension->expects($this->once())
            ->method('getValue')
            ->willReturn($dimensionValue);
        $this->scopeResolver->expects($this->once())
            ->method('getScope')
            ->willReturn($this->scopeInterface);
        $this->scopeInterface->expects($this->once())
            ->method('getId')
            ->willReturn($dimensionValue);
        $this->intervalFactory->expects($this->once())
            ->method('create')
            ->willReturn($interval);

        $this->assertEquals(
            $interval,
            $this->model->getInterval(
                $bucket,
                [$dimension],
                $this->entityStorage
            )
        );
    }

    /**
     * Test getAggregation() method
     */
    public function testGetAggregation()
    {
        $expectedResult = [
            1 => 1,
        ];
        $bucket = $this->getMockBuilder(\Magento\Framework\Search\Request\BucketInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $dimension = $this->getMockBuilder(\Magento\Framework\Search\Request\Dimension::class)
            ->setMethods(['getValue'])
            ->disableOriginalConstructor()
            ->getMock();
        $dimension->expects($this->never())
            ->method('getValue');
        $this->scopeResolver->expects($this->never())
            ->method('getScope');
        $this->scopeInterface->expects($this->never())
            ->method('getId');

        $this->clientMock->expects($this->once())
            ->method('query')
            ->with($this->callback(function ($query) {
                $histogramParams = $query['body']['aggregations']['prices']['histogram'];
                // Assert the interval is queried as a float. See MAGETWO-95471
                if ($histogramParams['interval'] !== 10.0) {
                    return false;
                }
                if (!isset($histogramParams['min_doc_count']) || $histogramParams['min_doc_count'] !== 1) {
                    return false;
                }
                return true;
            }))
            ->willReturn([
                'aggregations' => [
                    'prices' => [
                        'buckets' => [
                            [
                                'key' => 1,
                                'doc_count' => 1,
                            ],
                        ],
                    ],
                ],
            ]);

        $this->queryContainer->expects($this->once())
            ->method('getQuery')
            ->willReturn([]);

        $this->assertEquals(
            $expectedResult,
            $this->model->getAggregation(
                $bucket,
                [$dimension],
                10,
                $this->entityStorage
            )
        );
    }

    /**
     * Test prepareData() method
     */
    public function testPrepareData()
    {
        $expectedResult = [
            [
                'from' => '',
                'to' => 10,
                'count' => 1,
            ],
            [
                'from' => 10,
                'to' => '',
                'count' => 1,
            ],
        ];
        $this->assertEquals(
            $expectedResult,
            $this->model->prepareData(
                10,
                [
                    1 => 1,
                    2 => 1,
                ]
            )
        );
    }
}
