<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Test\Unit\Model\Api\SearchCriteria\CollectionProcessor\FilterProcessor;

use Magento\Catalog\Model\Api\SearchCriteria\CollectionProcessor\FilterProcessor\ProductWebsiteFilter;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Framework\Api\Filter;

class ProductWebsiteFilterTest extends \PHPUnit\Framework\TestCase
{
    /** @var ProductWebsiteFilter */
    private $model;

    protected function setUp(): void
    {
        $this->model = new ProductWebsiteFilter();
    }

    public function testApply()
    {
        /** @var Filter|\PHPUnit\Framework\MockObject\MockObject $filterMock */
        $filterMock = $this->getMockBuilder(Filter::class)
            ->disableOriginalConstructor()
            ->getMock();

        /** @var Collection|\PHPUnit\Framework\MockObject\MockObject $collectionMock */
        $collectionMock = $this->getMockBuilder(Collection::class)
            ->disableOriginalConstructor()
            ->getMock();

        $filterMock->expects($this->once())
            ->method('getValue')
            ->willReturn('1,2');

        $collectionMock->expects($this->once())
            ->method('addWebsiteFilter')
            ->with(['1', '2']);

        $this->assertTrue($this->model->apply($filterMock, $collectionMock));
    }
}
