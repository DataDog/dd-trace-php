<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\Data\Test\Unit\Collection;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class DbTest extends \PHPUnit\Framework\TestCase
{
    use \Magento\Framework\TestFramework\Unit\Helper\SelectRendererTrait;

    /**
     * @var \Magento\Framework\Data\Collection\AbstractDb
     */
    protected $collection;

    /**
     * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $loggerMock;

    /**
     * @var \Magento\Framework\Data\Collection\EntityFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $entityFactoryMock;

    /**
     * @var \Magento\Framework\Data\Collection\Db\FetchStrategyInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $fetchStrategyMock;

    /**
     * @var \Magento\Framework\TestFramework\Unit\Helper\ObjectManager
     */
    protected $objectManager;

    protected function setUp(): void
    {
        $this->objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->fetchStrategyMock =
            $this->createPartialMock(\Magento\Framework\Data\Collection\Db\FetchStrategy\Query::class, ['fetchAll']);
        $this->entityFactoryMock =
            $this->createPartialMock(\Magento\Framework\Data\Collection\EntityFactory::class, ['create']);
        $this->loggerMock = $this->createMock(\Psr\Log\LoggerInterface::class);
        $this->collection = new \Magento\Framework\Data\Test\Unit\Collection\DbCollection(
            $this->entityFactoryMock,
            $this->loggerMock,
            $this->fetchStrategyMock
        );
    }

    protected function tearDown(): void
    {
        unset($this->collection);
    }

    public function testSetAddOrder()
    {
        $adapter = $this->createPartialMock(\Magento\Framework\DB\Adapter\Pdo\Mysql::class, ['fetchAll', 'select']);
        $renderer = $this->getSelectRenderer($this->objectManager);
        $select = new \Magento\Framework\DB\Select($adapter, $renderer);
        $adapter
            ->expects($this->any())
            ->method('select')
            ->willReturn($select);
        $this->collection->setConnection($adapter);

        $select = $this->collection->getSelect();
        $this->assertEmpty($select->getPart(\Magento\Framework\DB\Select::ORDER));

        /* Direct access to select object is available and many places are using it for sort order declaration */
        $select->order('select_field', \Magento\Framework\Data\Collection::SORT_ORDER_ASC);
        $this->collection->addOrder('some_field', \Magento\Framework\Data\Collection::SORT_ORDER_ASC);
        $this->collection->setOrder('other_field', \Magento\Framework\Data\Collection::SORT_ORDER_ASC);
        $this->collection->addOrder('other_field', \Magento\Framework\Data\Collection::SORT_ORDER_DESC);

        $this->collection->load();
        $selectOrders = $select->getPart(\Magento\Framework\DB\Select::ORDER);
        $this->assertEquals(['select_field', 'ASC'], array_shift($selectOrders));
        $this->assertEquals('some_field ASC', (string)array_shift($selectOrders));
        $this->assertEquals('other_field DESC', (string)array_shift($selectOrders));
        $this->assertEmpty(array_shift($selectOrders));
    }

    public function testUnshiftOrder()
    {
        $adapter = $this->createPartialMock(\Magento\Framework\DB\Adapter\Pdo\Mysql::class, ['fetchAll', 'select']);
        $renderer = $this->getSelectRenderer($this->objectManager);
        $select = new \Magento\Framework\DB\Select($adapter, $renderer);
        $adapter
            ->expects($this->any())
            ->method('select')
            ->willReturn($select);
        $this->collection->setConnection($adapter);
        $this->collection->addOrder('some_field', \Magento\Framework\Data\Collection::SORT_ORDER_ASC);
        $this->collection->unshiftOrder('other_field', \Magento\Framework\Data\Collection::SORT_ORDER_ASC);

        $this->collection->load();
        $selectOrders = $this->collection->getSelect()->getPart(\Magento\Framework\DB\Select::ORDER);
        $this->assertEquals('other_field ASC', (string)array_shift($selectOrders));
        $this->assertEquals('some_field ASC', (string)array_shift($selectOrders));
        $this->assertEmpty(array_shift($selectOrders));
    }

    /**
     * Test that adding field to filter builds proper sql WHERE condition
     */
    public function testAddFieldToFilter()
    {
        $adapter =
            $this->createPartialMock(\Magento\Framework\DB\Adapter\Pdo\Mysql::class, ['prepareSqlCondition', 'select']);
        $adapter->expects(
            $this->any()
        )->method(
            'prepareSqlCondition'
        )->with(
            $this->stringContains('is_imported'),
            $this->anything()
        )->willReturn(
            'is_imported = 1'
        );
        $renderer = $this->getSelectRenderer($this->objectManager);
        $select = new \Magento\Framework\DB\Select($adapter, $renderer);
        $adapter
            ->expects($this->any())
            ->method('select')
            ->willReturn($select);
        $this->collection->setConnection($adapter);
        $select = $this->collection->getSelect()->from('test');

        $this->collection->addFieldToFilter('is_imported', ['eq' => '1']);
        $this->assertEquals('SELECT `test`.* FROM `test` WHERE (is_imported = 1)', $select->assemble());
    }

    /**
     * Test that adding multiple fields to filter at once
     * builds proper sql WHERE condition and created conditions are joined with OR
     */
    public function testAddFieldToFilterWithMultipleParams()
    {
        $adapter =
            $this->createPartialMock(\Magento\Framework\DB\Adapter\Pdo\Mysql::class, ['prepareSqlCondition', 'select']);
        $adapter->expects(
            $this->exactly(3)
        )->method(
            'prepareSqlCondition'
        )->withConsecutive(
            ["`weight`", ['in' => [1, 3]]],
            ['`name`', ['like' => 'M%']],
            ['`is_imported`', $this->anything()]
        )->willReturnOnConsecutiveCalls(
            'weight in (1, 3)',
            "name like 'M%'",
            'is_imported = 1'
        );
        $renderer = $this->getSelectRenderer($this->objectManager);
        $select = new \Magento\Framework\DB\Select($adapter, $renderer);
        $adapter
            ->expects($this->any())
            ->method('select')
            ->willReturn($select);
        $this->collection->setConnection($adapter);
        $select = $this->collection->getSelect()->from("test");

        $this->collection->addFieldToFilter(
            ['weight', 'name'],
            [['in' => [1, 3]], ['like' => 'M%']]
        );

        $this->assertEquals(
            "SELECT `test`.* FROM `test` WHERE ((weight in (1, 3)) OR (name like 'M%'))",
            $select->assemble()
        );
        $this->collection->addFieldToFilter('is_imported', ['eq' => '1']);
        $this->assertEquals(
            "SELECT `test`.* FROM `test` WHERE ((weight in (1, 3)) OR (name like 'M%')) AND (is_imported = 1)",
            $select->assemble()
        );
    }

    /**
     * Test that adding field to filter by value which contains question mark produce correct SQL
     */
    public function testAddFieldToFilterValueContainsQuestionMark()
    {
        $adapter = $this->createPartialMock(
            \Magento\Framework\DB\Adapter\Pdo\Mysql::class,
            ['select', 'prepareSqlCondition', 'supportStraightJoin']
        );
        $adapter->expects(
            $this->once()
        )->method(
            'prepareSqlCondition'
        )->with(
            '`email`',
            ['like' => 'value?']
        )->willReturn(
            'email LIKE \'%value?%\''
        );
        $renderer = $this->getSelectRenderer($this->objectManager);
        $select = new \Magento\Framework\DB\Select($adapter, $renderer);
        $adapter
            ->expects($this->any())
            ->method('select')
            ->willReturn($select);
        $this->collection->setConnection($adapter);

        $select = $this->collection->getSelect()->from('test');
        $this->collection->addFieldToFilter('email', ['like' => 'value?']);
        $this->assertEquals("SELECT `test`.* FROM `test` WHERE (email LIKE '%value?%')", $select->assemble());
    }

    /**
     * Test that field is quoted when added to SQL via addFieldToFilter()
     */
    public function testAddFieldToFilterFieldIsQuoted()
    {
        $adapter = $this->createPartialMock(
            \Magento\Framework\DB\Adapter\Pdo\Mysql::class,
            ['quoteIdentifier', 'prepareSqlCondition', 'select']
        );
        $adapter->expects(
            $this->once()
        )->method(
            'quoteIdentifier'
        )->with(
            'email'
        )->willReturn(
            '`email`'
        );
        $adapter->expects(
            $this->any()
        )->method(
            'prepareSqlCondition'
        )->with(
            $this->stringContains('`email`'),
            $this->anything()
        )->willReturn(
            '`email` = "foo@example.com"'
        );
        $renderer = $this->getSelectRenderer($this->objectManager);
        $select = new \Magento\Framework\DB\Select($adapter, $renderer);
        $adapter
            ->expects($this->any())
            ->method('select')
            ->willReturn($select);
        $this->collection->setConnection($adapter);
        $select = $this->collection->getSelect()->from('test');

        $this->collection->addFieldToFilter('email', ['eq' => 'foo@example.com']);
        $this->assertEquals('SELECT `test`.* FROM `test` WHERE (`email` = "foo@example.com")', $select->assemble());
    }

    /**
     * Test that after cloning collection $this->_select in initial and cloned collections
     * do not reference the same object
     *
     * @covers \Magento\Framework\Data\Collection\AbstractDb::__clone
     */
    public function testClone()
    {
        $adapter = $this->createMock(\Magento\Framework\DB\Adapter\Pdo\Mysql::class);
        $adapter
            ->expects($this->any())
            ->method('select')
            ->willReturn($this->createMock(\Magento\Framework\DB\Select::class));
        $this->collection->setConnection($adapter);
        $this->assertInstanceOf(\Magento\Framework\DB\Select::class, $this->collection->getSelect());

        $clonedCollection = clone $this->collection;

        $this->assertInstanceOf(\Magento\Framework\DB\Select::class, $clonedCollection->getSelect());
        $this->assertNotSame(
            $clonedCollection->getSelect(),
            $this->collection->getSelect(),
            'Collection was cloned but $this->_select in both initial and cloned collections reference the same object'
        );
    }

    /**
     * @param bool $printQuery
     * @param bool $printFlag
     * @param string $query
     * @param string $expected
     *
     * @dataProvider printLogQueryPrintingDataProvider
     */
    public function testPrintLogQueryPrinting($printQuery, $printFlag, $query, $expected)
    {
        $this->expectOutputString($expected);
        $this->collection->setFlag('print_query', $printFlag);
        $this->collection->printLogQuery($printQuery, false, $query);
    }

    /**
     * @return array
     */
    public function printLogQueryPrintingDataProvider()
    {
        return [
            [false, false, 'some_query', ''],
            [true, false, 'some_query', 'some_query'],
            [false, true, 'some_query', 'some_query']
        ];
    }

    /**
     * @param bool $logQuery
     * @param bool $logFlag
     * @param int $expectedCalls
     *
     * @dataProvider printLogQueryLoggingDataProvider
     */
    public function testPrintLogQueryLogging($logQuery, $logFlag, $expectedCalls)
    {
        $this->collection->setFlag('log_query', $logFlag);
        $this->loggerMock->expects($this->exactly($expectedCalls))->method('info');
        $this->collection->printLogQuery(false, $logQuery, 'some_query');
    }

    /**
     * @return array
     */
    public function printLogQueryLoggingDataProvider()
    {
        return [
            [true, false, 1],
            [false, true, 1],
            [false, false, 0],
        ];
    }

    public function testFetchItem()
    {
        $data = [1 => 'test'];
        $counter = 0;
        $statementMock = $this->createPartialMock(\Zend_Db_Statement_Pdo::class, ['fetch']);
        $statementMock->expects($this->exactly(2))
            ->method('fetch')
            ->willReturnCallback(function () use (&$counter, $data) {
                return ++$counter % 2 ? [] : $data;
            });

        $adapterMock = $this->createPartialMock(\Magento\Framework\DB\Adapter\Pdo\Mysql::class, ['select', 'query']);
        $selectMock = $this->getMockBuilder(\Magento\Framework\DB\Select::class)
            ->setConstructorArgs(
                [
                    'adapter' => $adapterMock,
                    'selectRenderer' => $this->getSelectRenderer($this->objectManager)
                ]
            )
            ->getMock();

        $adapterMock->expects($this->once())
            ->method('query')
            ->with($selectMock, $this->anything())
            ->willReturn($statementMock);
        $adapterMock->expects($this->once())
            ->method('select')
            ->willReturn($selectMock);

        $this->collection->setConnection($adapterMock);
        $this->assertFalse($this->collection->fetchItem());

        $objectMock = $this->createPartialMock(\Magento\Framework\DataObject::class, ['setData']);
        $objectMock->expects($this->once())
            ->method('setData')
            ->with($data);
        $this->entityFactoryMock->expects($this->once())
            ->method('create')
            ->with(\Magento\Framework\DataObject::class)
            ->willReturn($objectMock);

        $this->assertEquals($objectMock, $this->collection->fetchItem());
    }

    public function testGetSize()
    {
        $countSql = 500;
        $adapterMock = $this->createPartialMock(
            \Magento\Framework\DB\Adapter\Pdo\Mysql::class,
            ['select', 'quoteInto', 'prepareSqlCondition', 'fetchOne']
        );
        $selectMock = $this->getMockBuilder(\Magento\Framework\DB\Select::class)
            ->setMethods(['orWhere', 'where', 'reset', 'columns'])
            ->setConstructorArgs(
                [
                    'adapter' => $adapterMock,
                    'selectRenderer' => $this->getSelectRenderer($this->objectManager)
                ]
            )
            ->getMock();

        $selectMock->expects($this->exactly(4))
            ->method('reset');
        $selectMock->expects($this->once())
            ->method('columns')
            ->with('COUNT(*)');
        $adapterMock->expects($this->once())
            ->method('select')
            ->willReturn($selectMock);
        $adapterMock->expects($this->exactly(2))
            ->method('quoteInto')
            ->willReturnMap([
                ['testField1=?', 'testValue1', null, null, 'testField1=testValue1'],
                ['testField4=?', 'testValue4', null, null, 'testField4=testValue4'],
            ]);
        $selectMock->expects($this->once())
            ->method('orWhere')
            ->with('testField1=testValue1');
        $selectMock->expects($this->exactly(3))
            ->method('where')
            ->willReturnMap([
                ['testValue2', $this->returnSelf()],
                [
                    'testField3 = testValue3',
                    null,
                    \Magento\Framework\DB\Select::TYPE_CONDITION,
                    $this->returnSelf()
                ],
                ['testField4=testValue4', $this->returnSelf()],
            ]);
        $adapterMock->expects($this->once())
            ->method('prepareSqlCondition')
            ->with('testField3', 'testValue3')
            ->willReturn('testField3 = testValue3');
        $adapterMock->expects($this->once())
            ->method('fetchOne')
            ->with($selectMock, [])
            ->willReturn($countSql);

        $this->collection->addFilter('testField1', 'testValue1', 'or');
        $this->collection->addFilter('testField2', 'testValue2', 'string');
        $this->collection->addFilter('testField3', 'testValue3', 'public');
        $this->collection->addFilter('testField4', 'testValue4');
        $this->collection->setConnection($adapterMock);
        $this->assertEquals($countSql, $this->collection->getSize());
        $this->assertEquals($countSql, $this->collection->getSize());
    }

    public function testGetSelectSql()
    {
        $adapterMock = $this->createPartialMock(\Magento\Framework\DB\Adapter\Pdo\Mysql::class, ['select']);
        $selectMock = $this->getMockBuilder(\Magento\Framework\DB\Select::class)
            ->setMethods(['__toString'])
            ->setConstructorArgs(
                [
                    'adapter' => $adapterMock,
                    'selectRenderer' => $this->getSelectRenderer($this->objectManager)
                ]
            )
            ->getMock();

        $adapterMock->expects($this->once())
            ->method('select')
            ->willReturn($selectMock);

        $sql = 'query';
        $selectMock->expects($this->once())
            ->method('__toString')
            ->willReturn($sql);

        $this->collection->setConnection($adapterMock);
        $this->assertEquals($sql, $this->collection->getSelectSql(true));
        $this->assertEquals($selectMock, $this->collection->getSelectSql());
    }

    public function testGetData()
    {
        $adapterMock = $this->createPartialMock(
            \Magento\Framework\DB\Adapter\Pdo\Mysql::class,
            ['select', 'quoteInto', 'prepareSqlCondition', 'fetchOne']
        );
        $selectMock = $this->getMockBuilder(\Magento\Framework\DB\Select::class)
            ->setMethods(['orWhere', 'where', 'reset', 'columns'])
            ->setConstructorArgs(
                [
                    'adapter' => $adapterMock,
                    'selectRenderer' => $this->getSelectRenderer($this->objectManager)
                ]
            )
            ->getMock();

        $selectMock->expects($this->once())
            ->method('where')
            ->with('aliasField3 = testValue3', null, \Magento\Framework\DB\Select::TYPE_CONDITION)
            ->willReturnSelf();

        $adapterMock->expects($this->once())
            ->method('select')
            ->willReturn($selectMock);
        $adapterMock->expects($this->once())
            ->method('prepareSqlCondition')
            ->with('aliasField3', 'testValue3')
            ->willReturn('aliasField3 = testValue3');

        $this->collection->addFilter('testField3', 'testValue3', 'public');
        $this->collection->addFilterToMap('testFilter', 'testAlias', 'testGroup');
        $this->collection->addFilterToMap('testField3', 'aliasField3');

        $this->collection->setConnection($adapterMock);
        $this->assertNull($this->collection->getData());
    }

    /**
     * @dataProvider distinctDataProvider
     */
    public function testDistinct($flag, $expectedFlag)
    {
        $adapterMock = $this->createPartialMock(\Magento\Framework\DB\Adapter\Pdo\Mysql::class, ['select']);
        $selectMock = $this->getMockBuilder(\Magento\Framework\DB\Select::class)
            ->setMethods(['distinct'])
            ->setConstructorArgs(
                [
                    'adapter' => $adapterMock,
                    'selectRenderer' => $this->getSelectRenderer($this->objectManager)
                ]
            )
            ->getMock();

        $adapterMock->expects($this->once())
            ->method('select')
            ->willReturn($selectMock);
        $selectMock->expects($this->once())
            ->method('distinct')
            ->with($expectedFlag);

        $this->collection->setConnection($adapterMock);
        $this->collection->distinct($flag);
    }

    /**
     * @return array
     */
    public function distinctDataProvider()
    {
        return [
            [true, true],
            [false, false],
        ];
    }

    public function testToOptionHash()
    {
        $data = [10 => 'test'];
        $adapterMock = $this->createPartialMock(\Magento\Framework\DB\Adapter\Pdo\Mysql::class, ['select', 'query']);
        $selectMock = $this->getMockBuilder(\Magento\Framework\DB\Select::class)
            ->setConstructorArgs(
                [
                    'adapter' => $adapterMock,
                    'selectRenderer' => $this->getSelectRenderer($this->objectManager)
                ]
            )
            ->getMock();
        $adapterMock->expects($this->once())
            ->method('select')
            ->willReturn($selectMock);

        $this->fetchStrategyMock->expects($this->once())
            ->method('fetchAll')
            ->with($selectMock, [])
            ->willReturn([$data]);

        $objectMock = $this->createPartialMock(
            \Magento\Framework\DataObject::class,
            ['addData', 'setIdFieldName', 'getData']
        );
        $objectMock->expects($this->once())
            ->method('addData')
            ->with($data);
        $objectMock->expects($this->any())
            ->method('getData')
            ->willReturnMap([
                [null, null, 10],
                ['name', null, 'test'],
            ]);
        $this->entityFactoryMock->expects($this->once())
            ->method('create')
            ->with(\Magento\Framework\DataObject::class)
            ->willReturn($objectMock);

        $this->collection->setConnection($adapterMock);
        $this->collection->loadData(false, false);
        $this->collection->loadData(false, false);
        $this->assertEquals($data, $this->collection->toOptionHash());
    }
}
