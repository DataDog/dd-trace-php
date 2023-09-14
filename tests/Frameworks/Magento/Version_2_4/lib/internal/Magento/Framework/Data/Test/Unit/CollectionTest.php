<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\Data\Test\Unit;

use Magento\Framework\Data\Collection;
use Magento\Framework\Data\Collection\EntityFactory;
use Magento\Framework\DataObject;
use Magento\Framework\Url;
use PHPUnit\Framework\TestCase;

class CollectionTest extends TestCase
{
    /**
     * @var Collection
     */
    protected $_model;

    /**
     * Set up.
     */
    protected function setUp(): void
    {
        $this->_model = new Collection(
            $this->createMock(EntityFactory::class)
        );
    }

    /**
     * Test for method removeAllItems.
     *
     * @return void
     */
    public function testRemoveAllItems()
    {
        $this->_model->addItem(new DataObject());
        $this->_model->addItem(new DataObject());
        $this->assertCount(2, $this->_model->getItems());
        $this->_model->removeAllItems();
        $this->assertEmpty($this->_model->getItems());
    }

    /**
     * Test loadWithFilter()
     *
     * @return void
     */
    public function testLoadWithFilter()
    {
        $this->assertInstanceOf(Collection::class, $this->_model->loadWithFilter());
        $this->assertEmpty($this->_model->getItems());
        $this->_model->addItem(new DataObject());
        $this->_model->addItem(new DataObject());
        $this->assertCount(2, $this->_model->loadWithFilter()->getItems());
    }

    /**
     * Test for method etItemObjectClass
     *
     * @dataProvider setItemObjectClassDataProvider
     */
    public function testSetItemObjectClass($class)
    {
        $this->markTestSkipped('Skipped in #27500 due to testing protected/private methods and properties');

        $this->_model->setItemObjectClass($class);
        $this->assertAttributeSame($class, '_itemObjectClass', $this->_model);
    }

    /**
     * Data provider.
     *
     * @return array
     */
    public function setItemObjectClassDataProvider()
    {
        return [[Url::class], [DataObject::class]];
    }

    /**
     * Test for method setItemObjectClass with exception.
     */
    public function testSetItemObjectClassException()
    {
        $this->expectException('InvalidArgumentException');
        $this->expectExceptionMessage('Incorrect_ClassName does not extend \Magento\Framework\DataObject');
        $this->_model->setItemObjectClass('Incorrect_ClassName');
    }

    /**
     * Test for method addFilter.
     *
     * @return void
     */
    public function testAddFilter()
    {
        $this->_model->addFilter('field1', 'value');
        $this->assertEquals('field1', $this->_model->getFilter('field1')->getData('field'));
    }

    /**
     * Test for method getFilters.
     *
     * @return void
     */
    public function testGetFilters()
    {
        $this->_model->addFilter('field1', 'value');
        $this->_model->addFilter('field2', 'value');
        $this->assertEquals('field1', $this->_model->getFilter(['field1', 'field2'])[0]->getData('field'));
        $this->assertEquals('field2', $this->_model->getFilter(['field1', 'field2'])[1]->getData('field'));
    }

    /**
     * Test for method get non existion filters.
     *
     * @return void
     */
    public function testGetNonExistingFilters()
    {
        $this->assertEmpty($this->_model->getFilter([]));
        $this->assertEmpty($this->_model->getFilter('non_existing_filter'));
    }

    /**
     * Test for lag.
     *
     * @return void
     */
    public function testFlag()
    {
        $this->_model->setFlag('flag_name', 'flag_value');
        $this->assertEquals('flag_value', $this->_model->getFlag('flag_name'));
        $this->assertTrue($this->_model->hasFlag('flag_name'));
        $this->assertNull($this->_model->getFlag('non_existing_flag'));
    }

    /**
     * Test for method getCurPage.
     *
     * @return void
     */
    public function testGetCurPage()
    {
        $this->_model->setCurPage(1);
        $this->assertEquals(1, $this->_model->getCurPage());
    }

    /**
     * Test for method possibleFlowWithItem.
     *
     * @return void
     */
    public function testPossibleFlowWithItem()
    {
        $firstItemMock = $this->getMockBuilder(DataObject::class)
            ->addMethods(['getId'])
            ->onlyMethods(['getData', 'toArray'])
            ->disableOriginalConstructor()
            ->getMock();
        $secondItemMock = $this->getMockBuilder(DataObject::class)
            ->addMethods(['getId'])
            ->onlyMethods(['getData', 'toArray'])
            ->disableOriginalConstructor()
            ->getMock();
        $requiredFields = ['required_field_one', 'required_field_two'];
        $arrItems = [
            'totalRecords' => 1,
            'items' => [
                0 => 'value',
            ],
        ];
        $items = [
            'item_id' => $firstItemMock,
            0 => $secondItemMock,
        ];
        $firstItemMock->expects($this->exactly(2))->method('getId')->willReturn('item_id');

        $firstItemMock
            ->expects($this->atLeastOnce())
            ->method('getData')
            ->with('colName')
            ->willReturn('first_value');
        $secondItemMock
            ->expects($this->atLeastOnce())
            ->method('getData')
            ->with('colName')
            ->willReturn('second_value');

        $firstItemMock
            ->expects($this->once())
            ->method('toArray')
            ->with($requiredFields)
            ->willReturn('value');
        /** add items and set them values */
        $this->_model->addItem($firstItemMock);
        $this->assertEquals($arrItems, $this->_model->toArray($requiredFields));

        $this->_model->addItem($secondItemMock);
        $this->_model->setDataToAll('column', 'value');

        /** get items by column name */
        $this->assertEquals(['first_value', 'second_value'], $this->_model->getColumnValues('colName'));
        $this->assertEquals([$secondItemMock], $this->_model->getItemsByColumnValue('colName', 'second_value'));
        $this->assertEquals($firstItemMock, $this->_model->getItemByColumnValue('colName', 'second_value'));
        $this->assertEquals([], $this->_model->getItemsByColumnValue('colName', 'non_existing_value'));
        $this->assertNull($this->_model->getItemByColumnValue('colName', 'non_existing_value'));

        /** get items */
        $this->assertEquals(['item_id', 0], $this->_model->getAllIds());
        $this->assertEquals($firstItemMock, $this->_model->getFirstItem());
        $this->assertEquals($secondItemMock, $this->_model->getLastItem());
        $this->assertEquals($items, $this->_model->getItems('item_id'));

        /** remove existing items */
        $this->assertNull($this->_model->getItemById('not_existing_item_id'));
        $this->_model->removeItemByKey('item_id');
        $this->assertEquals([$secondItemMock], $this->_model->getItems());
        $this->_model->removeAllItems();
        $this->assertEquals([], $this->_model->getItems());
    }

    /**
     * Test for method eachCallsMethodOnEachItemWithNoArgs.
     *
     * @return void
     */
    public function testEachCallsMethodOnEachItemWithNoArgs()
    {
        for ($i = 0; $i < 3; $i++) {
            $item = $this->getMockBuilder(DataObject::class)
                ->addMethods(['testCallback'])
                ->disableOriginalConstructor()
                ->getMock();
            $item->expects($this->once())->method('testCallback')->with();
            $this->_model->addItem($item);
        }
        $this->_model->each('testCallback');
    }

    /**
     * Test for method eachCallsMethodOnEachItemWithArgs.
     *
     * @return void
     */
    public function testEachCallsMethodOnEachItemWithArgs()
    {
        for ($i = 0; $i < 3; $i++) {
            $item = $this->getMockBuilder(DataObject::class)
                ->addMethods(['testCallback'])
                ->disableOriginalConstructor()
                ->getMock();
            $item->expects($this->once())->method('testCallback')->with('a', 'b', 'c');
            $this->_model->addItem($item);
        }
        $this->_model->each('testCallback', ['a', 'b', 'c']);
    }

    /**
     * Test for method callsClosureWithEachItemAndNoArgs.
     *
     * @return void
     */
    public function testCallsClosureWithEachItemAndNoArgs()
    {
        for ($i = 0; $i < 3; $i++) {
            $item = $this->getMockBuilder(DataObject::class)
                ->addMethods(['testCallback'])
                ->disableOriginalConstructor()
                ->getMock();
            $item->expects($this->once())->method('testCallback')->with();
            $this->_model->addItem($item);
        }
        $this->_model->each(function ($item) {
            $item->testCallback();
        });
    }

    /**
     * Test for method callsClosureWithEachItemAndArgs.
     *
     * @return void
     */
    public function testCallsClosureWithEachItemAndArgs()
    {
        for ($i = 0; $i < 3; $i++) {
            $item = $this->getMockBuilder(DataObject::class)
                ->addMethods(['testItemCallback'])
                ->disableOriginalConstructor()
                ->getMock();
            $item->expects($this->once())->method('testItemCallback')->with('a', 'b', 'c');
            $this->_model->addItem($item);
        }
        $this->_model->each(function ($item, ...$args) {
            $item->testItemCallback(...$args);
        }, ['a', 'b', 'c']);
    }

    /**
     * Test for method callsCallableArrayWithEachItemNoArgs.
     *
     * @return void
     */
    public function testCallsCallableArrayWithEachItemNoArgs()
    {
        $mockCallbackObject = $this->getMockBuilder('DummyEachCallbackInstance')
            ->setMethods(['testObjCallback'])
            ->getMock();
        $mockCallbackObject->method('testObjCallback')->willReturnCallback(function ($item, ...$args) {
            $item->testItemCallback(...$args);
        });

        for ($i = 0; $i < 3; $i++) {
            $item = $this->getMockBuilder(DataObject::class)
                ->addMethods(['testItemCallback'])
                ->disableOriginalConstructor()
                ->getMock();
            $item->expects($this->once())->method('testItemCallback')->with();
            $this->_model->addItem($item);
        }

        $this->_model->each([$mockCallbackObject, 'testObjCallback']);
    }

    /**
     * Test for method callsCallableArrayWithEachItemAndArgs.
     *
     * @return void
     */
    public function testCallsCallableArrayWithEachItemAndArgs()
    {
        $mockCallbackObject = $this->getMockBuilder('DummyEachCallbackInstance')
            ->setMethods(['testObjCallback'])
            ->getMock();
        $mockCallbackObject->method('testObjCallback')->willReturnCallback(function ($item, ...$args) {
            $item->testItemCallback(...$args);
        });

        for ($i = 0; $i < 3; $i++) {
            $item = $this->getMockBuilder(DataObject::class)
                ->addMethods(['testItemCallback'])
                ->disableOriginalConstructor()
                ->getMock();
            $item->expects($this->once())->method('testItemCallback')->with('a', 'b', 'c');
            $this->_model->addItem($item);
        }

        $callback = [$mockCallbackObject, 'testObjCallback'];
        $this->_model->each($callback, ['a', 'b', 'c']);
    }
}
