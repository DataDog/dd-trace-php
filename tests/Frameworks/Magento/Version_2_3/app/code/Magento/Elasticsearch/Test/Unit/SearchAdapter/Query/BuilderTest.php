<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Elasticsearch\Test\Unit\SearchAdapter\Query;

use Magento\Elasticsearch\SearchAdapter\Query\Builder;
use Magento\Framework\Search\RequestInterface;
use Magento\Elasticsearch\Model\Config;
use Magento\Elasticsearch\SearchAdapter\SearchIndexNameResolver;
use Magento\Elasticsearch\SearchAdapter\Query\Builder\Aggregation as AggregationBuilder;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class BuilderTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var Builder
     */
    protected $model;

    /**
     * @var Config|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $clientConfig;

    /**
     * @var SearchIndexNameResolver|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $searchIndexNameResolver;

    /**
     * @var AggregationBuilder|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $aggregationBuilder;

    /**
     * @var RequestInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $request;

    /**
     * @var \Magento\Framework\App\ScopeResolverInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $scopeResolver;

    /**
     * @var \Magento\Framework\App\ScopeInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $scopeInterface;

    /**
     * Setup method
     * @return void
     */
    protected function setUp(): void
    {
        $this->clientConfig = $this->getMockBuilder(\Magento\Elasticsearch\Model\Config::class)
            ->setMethods(['getEntityType'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->searchIndexNameResolver = $this
            ->getMockBuilder(\Magento\Elasticsearch\SearchAdapter\SearchIndexNameResolver::class)
            ->setMethods(['getIndexName'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->aggregationBuilder = $this
            ->getMockBuilder(\Magento\Elasticsearch\SearchAdapter\Query\Builder\Aggregation::class)
            ->setMethods(['build'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->request = $this->getMockBuilder(\Magento\Framework\Search\RequestInterface::class)
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

        $objectManagerHelper = new ObjectManagerHelper($this);
        $this->model = $objectManagerHelper->getObject(
            \Magento\Elasticsearch\SearchAdapter\Query\Builder::class,
            [
                'clientConfig' => $this->clientConfig,
                'searchIndexNameResolver' => $this->searchIndexNameResolver,
                'aggregationBuilder' => $this->aggregationBuilder,
                'scopeResolver' => $this->scopeResolver
            ]
        );
    }

    /**
     * Test initQuery() method
     */
    public function testInitQuery()
    {
        $dimensionValue = 1;
        $dimension = $this->getMockBuilder(\Magento\Framework\Search\Request\Dimension::class)
            ->setMethods(['getValue'])
            ->disableOriginalConstructor()
            ->getMock();

        $this->request->expects($this->once())
            ->method('getDimensions')
            ->willReturn([$dimension]);
        $dimension->expects($this->once())
            ->method('getValue')
            ->willReturn($dimensionValue);
        $this->scopeResolver->expects($this->once())
            ->method('getScope')
            ->willReturn($this->scopeInterface);
        $this->scopeInterface->expects($this->once())
            ->method('getId')
            ->willReturn($dimensionValue);
        $this->request->expects($this->once())
            ->method('getFrom')
            ->willReturn(0);
        $this->request->expects($this->once())
            ->method('getSize')
            ->willReturn(10);
        $this->request->expects($this->once())
            ->method('getIndex')
            ->willReturn('catalogsearch_fulltext');
        $this->searchIndexNameResolver->expects($this->once())
            ->method('getIndexName')
            ->willReturn('indexName');
        $this->clientConfig->expects($this->once())
            ->method('getEntityType')
            ->willReturn('document');
        $this->model->initQuery($this->request);
    }

    /**
     * Test initQuery() method
     */
    public function testInitAggregations()
    {
        $this->aggregationBuilder->expects($this->any())
            ->method('build')
            ->willReturn([]);
        $result = $this->model->initAggregations($this->request, []);
        $this->assertNotNull($result);
    }
}
