<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\Filter\Test\Unit\DataObject;

use Laminas\Filter\FilterInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Filter\DataObject\Grid;

use PHPUnit\Framework\TestCase;

class GridTest extends TestCase
{
    public function testFilter()
    {
        $entityFactoryMock = $this->getMockForAbstractClass(EntityFactoryInterface::class);
        $entityFactoryMock
            ->expects($this->any())
            ->method('create')
            ->with(DataObject::class, [])
            ->willReturnCallback(
                function () {
                    return new DataObject();
                }
            );

        $gridFilter = new Grid($entityFactoryMock);
        $grid = [
            new DataObject(['field1' => 'value11', 'field2' => 'value12']),
            new DataObject(['field3' => 'value23', 'field2' => 'value22']),
        ];

        /** @var FilterInterface $filterMock */
        /** This filter should be applied to all fields values */
        $filterMock = $this->createMock(FilterInterface::class);
        $filterMock->expects($this->exactly(4))->method('filter')->willReturnCallback(
            function ($input) {
                return '(' . $input . ')';
            }
        );
        $gridFilter->addFilter($filterMock);

        /** @var FilterInterface $fieldFilterMock */
        /** This filter should be applied to 'field2' field value only */
        $fieldFilterMock = $this->createMock(FilterInterface::class);
        $fieldFilterMock->expects($this->exactly(2))->method('filter')->willReturnCallback(
            function ($input) {
                return '[' . $input . ']';
            }
        );
        $gridFilter->addFilter($fieldFilterMock, 'field2');

        /** Execute SUT and ensure that data of grid items was filtered correctly */
        $filteredGrid = $gridFilter->filter($grid);
        $this->assertCount(2, $filteredGrid, 'Quantity of filtered items is invalid.');
        $this->assertEquals(
            ['field1' => '(value11)', 'field2' => '[(value12)]'],
            $filteredGrid[0]->getData(),
            'First grid item was filtered incorrectly.'
        );
        $this->assertEquals(
            ['field3' => '(value23)', 'field2' => '[(value22)]'],
            $filteredGrid[1]->getData(),
            'Second grid item was filtered incorrectly.'
        );
    }
}
