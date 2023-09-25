<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Elasticsearch\Test\Unit\SearchAdapter\Aggregation\Builder;

use Magento\Elasticsearch\SearchAdapter\Aggregation\Builder\Term;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;

class TermTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var Term
     */
    private $model;

    /**
     * @var \Magento\Framework\Search\Request\BucketInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $requestBuckedInterface;

    /**
     * @var \Magento\Framework\Search\Dynamic\DataProviderInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $dataProviderContainer;

    /**
     * @var \Magento\Framework\Search\Request\Aggregation\TermBucket|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $bucket;

    /**
     * Set up test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->requestBuckedInterface = $this->getMockBuilder(\Magento\Framework\Search\Request\BucketInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->dataProviderContainer = $this->getMockBuilder(
            \Magento\Framework\Search\Dynamic\DataProviderInterface::class
        )
            ->disableOriginalConstructor()
            ->getMock();

        $this->bucket = $this->getMockBuilder(\Magento\Framework\Search\Request\Aggregation\TermBucket::class)
            ->setMethods(['getName'])
            ->disableOriginalConstructor()
            ->getMock();

        $objectManagerHelper = new ObjectManagerHelper($this);

        $this->model = $objectManagerHelper->getObject(
            \Magento\Elasticsearch\SearchAdapter\Aggregation\Builder\Term::class,
            []
        );
    }

    /**
     * Test build() method
     */
    public function testBuild()
    {
        $dimensions = [
            'scope' => [
                'name' => 'scope',
                'value' => 1,
            ],
        ];

        $queryResult = [
            'took' => 1,
            'timed_out' => false,
            '_shards' => [],
            'hits' => [
                'total' => 1,
                'max_score' => 1,
                'hits' => [],
            ],
            'aggregations' => [
                'category_bucket' => [
                    'buckets' => [
                        [
                            'key' => '23',
                            'doc_count' => 12,
                        ],
                    ],
                ],
            ],
        ];

        $this->bucket->expects($this->once())
            ->method('getName')
            ->willReturn('category_bucket');

        $this->model->build(
            $this->bucket,
            $dimensions,
            $queryResult,
            $this->dataProviderContainer
        );
    }
}
