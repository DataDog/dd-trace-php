<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Search\Test\Unit\Model;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;

class QueryTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Search\Model\Query
     */
    private $model;

    /**
     * @var \Magento\Search\Model\ResourceModel\Query|\PHPUnit\Framework\MockObject\MockObject
     */
    private $resource;

    protected function setUp(): void
    {
        $objectManager = new ObjectManager($this);

        $this->resource = $this->getMockBuilder(\Magento\Search\Model\ResourceModel\Query::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->model = $objectManager->getObject(\Magento\Search\Model\Query::class, ['resource' => $this->resource]);
    }

    public function testSaveNumResults()
    {
        $this->resource->expects($this->once())
            ->method('saveNumResults')
            ->with($this->model);

        $result = $this->model->saveNumResults(30);

        $this->assertEquals($this->model, $result);
        $this->assertEquals(30, $this->model->getNumResults());
    }

    public function testSaveIncrementalPopularity()
    {
        $this->resource->expects($this->once())
            ->method('saveIncrementalPopularity')
            ->with($this->model);

        $result = $this->model->saveIncrementalPopularity();

        $this->assertEquals($this->model, $result);
    }
}
