<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\DB\Test\Unit\Ddl;

/**
 * Class TriggerTest
 */
class TriggerTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\DB\Ddl\Trigger
     */
    protected $_object;

    protected function setUp(): void
    {
        $this->_object = new \Magento\Framework\DB\Ddl\Trigger();
    }

    /**
     * Test getListOfEvents() method
     */
    public function testGetListOfEvents()
    {
        $actualEventTypes = \Magento\Framework\DB\Ddl\Trigger::getListOfEvents();
        $this->assertIsArray($actualEventTypes);
        $this->assertCount(3, $actualEventTypes);
        $this->assertTrue(in_array(\Magento\Framework\DB\Ddl\Trigger::EVENT_INSERT, $actualEventTypes));
        $this->assertTrue(in_array(\Magento\Framework\DB\Ddl\Trigger::EVENT_UPDATE, $actualEventTypes));
        $this->assertTrue(in_array(\Magento\Framework\DB\Ddl\Trigger::EVENT_DELETE, $actualEventTypes));
    }

    /**
     * Test getListOfTimes() method
     */
    public function testGetListOfTimes()
    {
        $actualTimeTypes = \Magento\Framework\DB\Ddl\Trigger::getListOfTimes();
        $this->assertIsArray($actualTimeTypes);
        $this->assertCount(2, $actualTimeTypes);
        $this->assertTrue(in_array(\Magento\Framework\DB\Ddl\Trigger::TIME_AFTER, $actualTimeTypes));
        $this->assertTrue(in_array(\Magento\Framework\DB\Ddl\Trigger::TIME_BEFORE, $actualTimeTypes));
    }

    /**
     * Test case for getName() after setName()
     */
    public function testGetNameWithSetName()
    {
        $triggerName = 'TEST_TRIGGER_NAME' . random_int(100, 999);

        $this->_object->setName($triggerName);
        $this->assertEquals(strtolower($triggerName), $this->_object->getName());
    }

    /**
     * Test case for setName() with exception
     *
     */
    public function testSetNameWithException()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Trigger name should be a string');

        $triggerName = new \stdClass();
        //non string

        $this->_object->setName($triggerName);
    }

    /**
     * Test case for setTable() with exception
     *
     */
    public function testSetTableWithException()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Trigger table name should be a string');

        $tableName = new \stdClass();
        //non string

        $this->_object->setTable($tableName);
    }

    /**
     * Test for table name setter
     */
    public function testSetTableName()
    {
        $names = ['PREFIX_table', 'prefix_table'];
        foreach ($names as $name) {
            $this->_object->setTable($name);
            $this->assertEquals($name, $this->_object->getTable());
        }
    }

    /**
     * Test case for getName()
     *
     */
    public function testGetNameWithException()
    {
        $this->expectException(\Zend_Db_Exception::class);
        $this->expectExceptionMessage('Trigger name is not defined');

        $tableName = 'TEST_TABLE_NAME_' . random_int(100, 999);
        $event = \Magento\Framework\DB\Ddl\Trigger::EVENT_INSERT;

        $this->_object->setTable($tableName)->setTime(\Magento\Framework\DB\Ddl\Trigger::TIME_AFTER)->setEvent($event);

        $this->_object->getName();
    }

    /**
     * Test case for getTime() with Exception
     *
     */
    public function testGetTimeWithException()
    {
        $this->expectException(\Zend_Db_Exception::class);
        $this->expectExceptionMessage('Trigger time is not defined');

        $tableName = 'TEST_TABLE_NAME_' . random_int(100, 999);
        $event = \Magento\Framework\DB\Ddl\Trigger::EVENT_INSERT;

        $this->_object->setTable($tableName)->setEvent($event);

        $this->_object->getTime();
    }

    /**
     * Test case for getTable()
     *
     */
    public function testGetTableWithException()
    {
        $this->expectException(\Zend_Db_Exception::class);
        $this->expectExceptionMessage('Trigger table name is not defined');

        $event = \Magento\Framework\DB\Ddl\Trigger::EVENT_INSERT;

        $this->_object->setTime(\Magento\Framework\DB\Ddl\Trigger::TIME_AFTER)->setEvent($event);

        $this->_object->getTable();
    }

    /**
     * Test case for getEvent() with Exception
     *
     */
    public function testGetEventWithException()
    {
        $this->expectException(\Zend_Db_Exception::class);
        $this->expectExceptionMessage('Trigger event is not defined');

        $tableName = 'TEST_TABLE_NAME_' . random_int(100, 999);

        $this->_object->setTable($tableName)->setTime(\Magento\Framework\DB\Ddl\Trigger::TIME_AFTER);

        $this->_object->getEvent();
    }

    /**
     */
    public function testWrongEventTypeException()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Trigger unsupported event type');

        $this->_object->setEvent('UNSUPORT EVENT TYPE');
    }

    /**
     */
    public function testWrongTimeTypeException()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Trigger unsupported time type');

        $this->_object->setTime('UNSUPORT TIME TYPE');
    }

    /**
     * Test case for setTable() with exception
     *
     */
    public function testAddStatementWithException()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Trigger statement should be a string');

        $statement = new \stdClass();
        //non string

        $this->_object->addStatement($statement);
    }

    /**
     * Data provider for setBody function
     */
    public function getStatementsDataProvider()
    {
        return [['SQL', ['SQL;']], ['SQL;', ['SQL;']]];
    }

    /**
     * Test addStatement and getStatements for trigger
     *
     * @dataProvider getStatementsDataProvider
     */
    public function testAddStatement($param, $expected)
    {
        $this->_object->addStatement($param);
        $this->assertEquals($expected, $this->_object->getStatements());
    }

    /**
     * Test getStatements method
     */
    public function testGetStatements()
    {
        $this->assertEquals([], $this->_object->getStatements());
    }
}
