<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Eav\Test\Unit\Model\Api\SearchCriteria\CollectionProcessor\FilterProcessor;

use Magento\Eav\Model\Api\SearchCriteria\CollectionProcessor\FilterProcessor\AttributeGroupAttributeSetIdFilter;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Group\Collection;
use Magento\Framework\Api\Filter;
use PHPUnit\Framework\TestCase;

class AttributeGroupAttributeSetIdFilterTest extends TestCase
{
    /**
     * @var AttributeGroupAttributeSetIdFilter
     */
    private $filter;

    protected function setUp(): void
    {
        $this->filter = new AttributeGroupAttributeSetIdFilter();
    }

    public function testApply()
    {
        $filterValue = 'filter_value';

        $filterMock = $this->getMockBuilder(Filter::class)
            ->disableOriginalConstructor()
            ->getMock();
        $filterMock->expects($this->once())
            ->method('getValue')
            ->willReturn($filterValue);

        $collectionMock = $this->getMockBuilder(Collection::class)
            ->disableOriginalConstructor()
            ->getMock();
        $collectionMock->expects($this->once())
            ->method('setAttributeSetFilter')
            ->with($filterValue)
            ->willReturnSelf();

        $this->assertTrue($this->filter->apply($filterMock, $collectionMock));
    }
}
