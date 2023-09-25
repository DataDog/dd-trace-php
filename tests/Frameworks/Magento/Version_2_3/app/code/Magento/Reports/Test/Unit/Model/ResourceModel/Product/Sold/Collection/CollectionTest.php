<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Reports\Test\Unit\Model\ResourceModel\Product\Sold\Collection;

use Magento\Framework\DB\Select;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Reports\Model\ResourceModel\Product\Sold\Collection;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CollectionTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var ObjectManager
     */
    protected $objectManager;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $selectMock;

    protected function setUp(): void
    {
        $this->objectManager = new ObjectManager($this);
        $this->selectMock = $this->createMock(Select::class);
    }

    public function testGetSelectCountSql()
    {
        /** @var $collection \PHPUnit\Framework\MockObject\MockObject */
        $collection = $this->getMockBuilder(Collection::class)
            ->setMethods(['getSelect'])
            ->disableOriginalConstructor()
            ->getMock();

        $collection->expects($this->atLeastOnce())->method('getSelect')->willReturn($this->selectMock);

        $this->selectMock->expects($this->atLeastOnce())->method('reset')->willReturnSelf();
        $this->selectMock->expects($this->exactly(2))->method('columns')->willReturnSelf();

        $this->selectMock->expects($this->at(6))->method('columns')->with('COUNT(DISTINCT main_table.entity_id)');

        $this->selectMock->expects($this->at(7))->method('reset')->with(Select::COLUMNS);
        $this->selectMock->expects($this->at(8))->method('columns')->with('COUNT(DISTINCT order_items.item_id)');

        $this->assertEquals($this->selectMock, $collection->getSelectCountSql());
    }
}
