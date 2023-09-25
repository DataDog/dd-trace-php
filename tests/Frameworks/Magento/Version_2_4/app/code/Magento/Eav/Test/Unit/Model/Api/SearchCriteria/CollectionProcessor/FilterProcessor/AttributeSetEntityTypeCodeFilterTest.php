<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Eav\Test\Unit\Model\Api\SearchCriteria\CollectionProcessor\FilterProcessor;

use Magento\Eav\Model\Api\SearchCriteria\CollectionProcessor\FilterProcessor\AttributeSetEntityTypeCodeFilter;
use Magento\Eav\Model\Config;
use Magento\Eav\Model\Entity\Type;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Set\Collection;
use Magento\Framework\Api\Filter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AttributeSetEntityTypeCodeFilterTest extends TestCase
{
    /**
     * @var AttributeSetEntityTypeCodeFilter
     */
    private $filter;

    /**
     * @var Config|MockObject
     */
    private $eavConfig;

    protected function setUp(): void
    {
        $this->eavConfig = $this->getMockBuilder(Config::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->filter = new AttributeSetEntityTypeCodeFilter($this->eavConfig);
    }

    public function testApply()
    {
        $filterValue = 'filter_value';
        $entityTypeId = 1;

        $entityTypeMock = $this->getMockBuilder(Type::class)
            ->disableOriginalConstructor()
            ->getMock();
        $entityTypeMock->expects($this->once())
            ->method('getId')
            ->willReturn($entityTypeId);

        $this->eavConfig->expects($this->once())
            ->method('getEntityType')
            ->with($filterValue)
            ->willReturn($entityTypeMock);

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
            ->method('setEntityTypeFilter')
            ->with($entityTypeId)
            ->willReturnSelf();

        $this->assertTrue($this->filter->apply($filterMock, $collectionMock));
    }
}
