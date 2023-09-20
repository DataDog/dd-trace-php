<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Backend\Test\Unit\Block\Widget\Grid;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ColumnSetTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Backend\Block\Widget\Grid\ColumnSet
     */
    protected $_block;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $_layoutMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $_columnMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $_factoryMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $_subtotalsMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $_totalsMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $_gridMock;

    protected function setUp(): void
    {
        $this->_columnMock = $this->createPartialMock(
            \Magento\Backend\Block\Widget\Grid\Column::class,
            ['setSortable', 'setRendererType', 'setFilterType']
        );
        $this->_layoutMock = $this->createMock(\Magento\Framework\View\Layout::class);
        $this->_layoutMock->expects(
            $this->any()
        )->method(
            'getChildBlocks'
        )->willReturn(
            ['column' => $this->_columnMock]
        );
        $this->_factoryMock = $this->createMock(\Magento\Backend\Model\Widget\Grid\Row\UrlGeneratorFactory::class);

        $this->_subtotalsMock = $this->createMock(\Magento\Backend\Model\Widget\Grid\SubTotals::class);

        $this->_totalsMock = $this->createMock(\Magento\Backend\Model\Widget\Grid\Totals::class);

        $arguments = [
            'layout' => $this->_layoutMock,
            'generatorFactory' => $this->_factoryMock,
            'totals' => $this->_totalsMock,
            'subtotals' => $this->_subtotalsMock,
        ];

        $objectManagerHelper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->_block = $objectManagerHelper->getObject(
            \Magento\Backend\Block\Widget\Grid\ColumnSet::class,
            $arguments
        );
        $this->_block->setNameInLayout('grid.columnSet');
    }

    protected function tearDown(): void
    {
        unset($this->_block);
        unset($this->_layoutMock);
        unset($this->_columnMock);
        unset($this->_factoryMock);
        unset($this->_totalsMock);
        unset($this->_subtotalsMock);
    }

    public function testSetSortablePropagatesSortabilityToChildren()
    {
        $this->_columnMock->expects($this->once())->method('setSortable')->with(false);
        $this->_block->setSortable(false);
    }

    public function testSetSortablePropagatesSortabilityToChildrenOnlyIfSortabilityIsFalse()
    {
        $this->_columnMock->expects($this->never())->method('setSortable');
        $this->_block->setSortable(true);
    }

    public function testSetRendererTypePropagatesRendererTypeToColumns()
    {
        $this->_columnMock->expects($this->once())->method('setRendererType')->with('renderer', 'Renderer_Class');
        $this->_block->setRendererType('renderer', 'Renderer_Class');
    }

    public function testSetFilterTypePropagatesFilterTypeToColumns()
    {
        $this->_columnMock->expects($this->once())->method('setFilterType')->with('filter', 'Filter_Class');
        $this->_block->setFilterType('filter', 'Filter_Class');
    }

    public function testGetRowUrlIfUrlPathNotSet()
    {
        $this->assertEquals('#', $this->_block->getRowUrl(new \stdClass()));
    }

    public function testGetRowUrl()
    {
        $generatorClass = \Magento\Backend\Model\Widget\Grid\Row\UrlGenerator::class;

        $itemMock = $this->createMock(\Magento\Framework\DataObject::class);

        $rowUrlGenerator =
            $this->createPartialMock(\Magento\Backend\Model\Widget\Grid\Row\UrlGenerator::class, ['getUrl']);
        $rowUrlGenerator->expects(
            $this->once()
        )->method(
            'getUrl'
        )->with(
            $this->equalTo($itemMock)
        )->willReturn(
            'http://localhost/mng/item/edit'
        );

        $factoryMock = $this->createPartialMock(
            \Magento\Backend\Model\Widget\Grid\Row\UrlGeneratorFactory::class,
            ['createUrlGenerator']
        );
        $factoryMock->expects(
            $this->once()
        )->method(
            'createUrlGenerator'
        )->with(
            $this->equalTo($generatorClass),
            $this->equalTo(['args' => ['generatorClass' => $generatorClass]])
        )->willReturn(
            $rowUrlGenerator
        );

        $arguments = [
            'layout' => $this->_layoutMock,
            'generatorFactory' => $factoryMock,
            'data' => ['rowUrl' => ['generatorClass' => $generatorClass]],
            'totals' => $this->_totalsMock,
            'subtotals' => $this->_subtotalsMock,
        ];

        $objectManagerHelper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        /** @var $model \Magento\Backend\Block\Widget\Grid\ColumnSet */
        $model = $objectManagerHelper->getObject(\Magento\Backend\Block\Widget\Grid\ColumnSet::class, $arguments);

        $url = $model->getRowUrl($itemMock);
        $this->assertEquals('http://localhost/mng/item/edit', $url);
    }

    public function testItemHasMultipleRows()
    {
        $item = new \Magento\Framework\DataObject();
        // prepare sub-collection
        $subCollection = new \Magento\Framework\Data\Collection(
            $this->createMock(\Magento\Framework\Data\Collection\EntityFactory::class)
        );
        $subCollection->addItem(new \Magento\Framework\DataObject(['test4' => '1', 'test5' => '2']));
        $subCollection->addItem(new \Magento\Framework\DataObject(['test4' => '2', 'test5' => '2']));
        $item->setChildren($subCollection);

        $this->assertTrue($this->_block->hasMultipleRows($item));
    }

    public function testShouldRenderTotalWithNotEmptyCollection()
    {
        $this->_prepareLayoutWithGrid($this->_prepareGridMock($this->_getTestCollection()));

        $this->_block->setCountTotals(true);
        $this->assertTrue($this->_block->shouldRenderTotal());
    }

    public function testShouldRenderTotalWithEmptyCollection()
    {
        $this->_prepareLayoutWithGrid(
            $this->_prepareGridMock(
                new \Magento\Framework\Data\Collection(
                    $this->createMock(\Magento\Framework\Data\Collection\EntityFactory::class)
                )
            )
        );

        $this->_block->setCountTotals(true);
        $this->assertFalse($this->_block->shouldRenderTotal());
    }

    public function testShouldRenderTotalWithFlagFalse()
    {
        $this->_block->setCountTotals(false);
        $this->assertFalse($this->_block->shouldRenderTotal());
    }

    public function testShouldRenderSubtotalWithFlagFalse()
    {
        $this->_block->setCountSubTotals(false);
        $this->assertFalse($this->_block->shouldRenderSubTotal(new \Magento\Framework\DataObject()));
    }

    public function testShouldRenderSubtotalWithEmptySubData()
    {
        $this->_block->setCountSubTotals(true);
        $this->assertFalse($this->_block->shouldRenderSubTotal(new \Magento\Framework\DataObject()));
    }

    public function testShouldRenderSubtotalWithNotEmptySubData()
    {
        $item = new \Magento\Framework\DataObject();
        // prepare sub-collection
        $subCollection = new \Magento\Framework\Data\Collection(
            $this->createMock(\Magento\Framework\Data\Collection\EntityFactory::class)
        );
        $subCollection->addItem(new \Magento\Framework\DataObject(['test4' => '1', 'test5' => '2']));
        $subCollection->addItem(new \Magento\Framework\DataObject(['test4' => '2', 'test5' => '2']));
        $item->setChildren($subCollection);

        $this->_block->setCountSubTotals(true);
        $this->assertTrue($this->_block->shouldRenderSubTotal($item));
    }

    public function testUpdateItemByFirstMultiRow()
    {
        $item = new \Magento\Framework\DataObject(['test1' => '1']);
        // prepare sub-collection
        $subCollection = new \Magento\Framework\Data\Collection(
            $this->createMock(\Magento\Framework\Data\Collection\EntityFactory::class)
        );
        $subCollection->addItem(new \Magento\Framework\DataObject(['test4' => '1', 'test5' => '2']));
        $subCollection->addItem(new \Magento\Framework\DataObject(['test4' => '2', 'test5' => '2']));
        $item->setChildren($subCollection);

        $expectedItem = new \Magento\Framework\DataObject(['test1' => '1']);
        $expectedItem->addData(['test4' => '1', 'test5' => '2']);
        $expectedItem->setChildren($subCollection);

        $this->_block->updateItemByFirstMultiRow($item);
        $this->assertEquals($expectedItem, $item);
    }

    public function testGetSubTotals()
    {
        // prepare sub-collection
        $subCollection = new \Magento\Framework\Data\Collection(
            $this->createMock(\Magento\Framework\Data\Collection\EntityFactory::class)
        );
        $subCollection->addItem(new \Magento\Framework\DataObject(['column' => '1']));
        $subCollection->addItem(new \Magento\Framework\DataObject(['column' => '1']));

        $this->_subtotalsMock->expects(
            $this->once()
        )->method(
            'countTotals'
        )->with(
            $subCollection
        )->willReturn(
            new \Magento\Framework\DataObject(['column' => '2'])
        );

        // prepare item
        $item = new \Magento\Framework\DataObject(['test1' => '1']);
        $item->setChildren($subCollection);

        $this->assertEquals(new \Magento\Framework\DataObject(['column' => '2']), $this->_block->getSubTotals($item));
    }

    public function testGetTotals()
    {
        $collection = $this->_getTestCollection();
        $this->_prepareLayoutWithGrid($this->_prepareGridMock($collection));

        $this->_totalsMock->expects(
            $this->once()
        )->method(
            'countTotals'
        )->with(
            $collection
        )->willReturn(
            new \Magento\Framework\DataObject(['test1' => '3', 'test2' => '2'])
        );

        $this->assertEquals(
            new \Magento\Framework\DataObject(['test1' => '3', 'test2' => '2']),
            $this->_block->getTotals()
        );
    }

    /**
     * Retrieve prepared mock for \Magento\Backend\Model\Widget\Grid with collection
     *
     * @param \Magento\Framework\Data\Collection $collection
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    protected function _prepareGridMock($collection)
    {
        // prepare block grid
        $gridMock = $this->createPartialMock(\Magento\Backend\Block\Widget\Grid::class, ['getCollection']);
        $gridMock->expects($this->any())->method('getCollection')->willReturn($collection);

        return $gridMock;
    }

    /**
     * Retrieve test collection
     *
     * @return \Magento\Framework\Data\Collection
     */
    protected function _getTestCollection()
    {
        $collection = new \Magento\Framework\Data\Collection(
            $this->createMock(\Magento\Framework\Data\Collection\EntityFactory::class)
        );
        $items = [
            new \Magento\Framework\DataObject(['test1' => '1', 'test2' => '2']),
            new \Magento\Framework\DataObject(['test1' => '1', 'test2' => '2']),
            new \Magento\Framework\DataObject(['test1' => '1', 'test2' => '2']),
        ];
        foreach ($items as $item) {
            $collection->addItem($item);
        }

        return $collection;
    }

    /**
     * Prepare layout for receiving grid block
     *
     * @param \PHPUnit\Framework\MockObject\MockObject $gridMock
     */
    protected function _prepareLayoutWithGrid($gridMock)
    {
        $this->_layoutMock->expects(
            $this->any()
        )->method(
            'getParentName'
        )->with(
            'grid.columnSet'
        )->willReturn(
            'grid'
        );
        $this->_layoutMock->expects(
            $this->any()
        )->method(
            'getBlock'
        )->with(
            'grid'
        )->willReturn(
            $gridMock
        );
    }
}
