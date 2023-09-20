<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Elasticsearch\Test\Unit\SearchAdapter\Filter;

use Magento\Elasticsearch\SearchAdapter\Filter\Builder;
use Magento\Elasticsearch\SearchAdapter\Filter\Builder\Range;
use Magento\Elasticsearch\SearchAdapter\Filter\Builder\Term;
use Magento\Elasticsearch\SearchAdapter\Filter\Builder\Wildcard;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;

class BuilderTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var Builder
     */
    protected $model;

    /**
     * @var Range|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $range;

    /**
     * @var Term|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $term;

    /**
     * @var Wildcard|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $wildcard;

    /**
     * Set up test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->range = $this->getMockBuilder(\Magento\Elasticsearch\SearchAdapter\Filter\Builder\Range::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->term = $this->getMockBuilder(\Magento\Elasticsearch\SearchAdapter\Filter\Builder\Term::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->wildcard = $this->getMockBuilder(\Magento\Elasticsearch\SearchAdapter\Filter\Builder\Wildcard::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->term->expects($this->any())
            ->method('buildFilter')
            ->willReturn([]);

        $objectManagerHelper = new ObjectManagerHelper($this);
        $this->model = $objectManagerHelper->getObject(
            \Magento\Elasticsearch\SearchAdapter\Filter\Builder::class,
            [
                'range' => $this->range,
                'term' => $this->term,
                'wildcard' => $this->wildcard
            ]
        );
    }

    /**
     * Test build() method failure
     */
    public function testBuildFailure()
    {
        $this->expectException(\InvalidArgumentException::class);

        $filter = $this->getMockBuilder(\Magento\Framework\Search\Request\FilterInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $filter->expects($this->any())
            ->method('getType')
            ->willReturn('unknown');

        $this->model->build($filter, 'must');
    }

    /**
     * Test build() method
     * @param string $filterMock
     * @param string $filterType
     * @dataProvider buildDataProvider
     */
    public function testBuild($filterMock, $filterType)
    {
        $filter = $this->getMockBuilder($filterMock)
            ->setMethods(['getMust', 'getType', 'getShould', 'getMustNot'])
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $filter->expects($this->any())
            ->method('getType')
            ->willReturn($filterType);
        $childFilter = $this->getMockBuilder(\Magento\Framework\Search\Request\FilterInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $childFilter->expects($this->any())
            ->method('getType')
            ->willReturn('termFilter');
        $filter->expects($this->any())
            ->method('getMust')
            ->willReturn([$childFilter]);
        $filter->expects($this->any())
            ->method('getShould')
            ->willReturn([$childFilter]);
        $filter->expects($this->any())
            ->method('getMustNot')
            ->willReturn([$childFilter]);

        $result = $this->model->build($filter, 'must');
        $this->assertNotNull($result);
    }

    /**
     * Test build() method with negation
     * @param string $filterMock
     * @param string $filterType
     * @dataProvider buildDataProvider
     */
    public function testBuildNegation($filterMock, $filterType)
    {
        $filter = $this->getMockBuilder($filterMock)
            ->setMethods(['getMust', 'getType', 'getShould', 'getMustNot'])
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $filter->expects($this->any())
            ->method('getType')
            ->willReturn($filterType);
        $childFilter = $this->getMockBuilder(\Magento\Framework\Search\Request\FilterInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $childFilter->expects($this->any())
            ->method('getType')
            ->willReturn('termFilter');
        $filter->expects($this->any())
            ->method('getMust')
            ->willReturn([$childFilter]);
        $filter->expects($this->any())
            ->method('getShould')
            ->willReturn([$childFilter]);
        $filter->expects($this->any())
            ->method('getMustNot')
            ->willReturn([$childFilter]);

        $result = $this->model->build($filter, 'must_not');
        $this->assertNotNull($result);
    }

    /**
     * @return array
     */
    public function buildDataProvider()
    {
        return [
            [\Magento\Framework\Search\Request\FilterInterface::class,
                'termFilter'
            ],
            [\Magento\Framework\Search\Request\Filter\BoolExpression::class,
                'boolFilter'
            ],
        ];
    }
}
