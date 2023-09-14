<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\Model\Test\Unit\ResourceModel\Db;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Adapter\DuplicateException;
use Magento\Framework\DB\Select;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Context;
use Magento\Framework\Model\ResourceModel\Db\ObjectRelationProcessor;
use Magento\Framework\Model\ResourceModel\Db\TransactionManagerInterface;
use Magento\Framework\Registry;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class AbstractDbTest extends TestCase
{
    /**
     * @var AbstractDb
     */
    protected $_model;

    /**
     * @var ResourceConnection
     */
    protected $_resourcesMock;

    /**
     * @var MockObject
     */
    protected $transactionManagerMock;

    /**
     * @var MockObject
     */
    protected $relationProcessorMock;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->_resourcesMock = $this->createMock(ResourceConnection::class);

        $this->relationProcessorMock =
            $this->createMock(ObjectRelationProcessor::class);
        $this->transactionManagerMock = $this->createMock(
            TransactionManagerInterface::class
        );
        $contextMock = $this->createMock(Context::class);
        $contextMock->expects($this->once())->method('getResources')->willReturn($this->_resourcesMock);
        $contextMock->expects($this->once())
            ->method('getObjectRelationProcessor')
            ->willReturn($this->relationProcessorMock);
        $contextMock->expects($this->once())
            ->method('getTransactionManager')
            ->willReturn($this->transactionManagerMock);

        $this->_model = $this->getMockForAbstractClass(
            AbstractDb::class,
            [$contextMock],
            '',
            true,
            true,
            true,
            ['_prepareDataForTable']
        );
    }

    /**
     * @param $fieldNameType
     * @param $expectedResult
     *
     * @return void
     * @dataProvider addUniqueFieldDataProvider
     */
    public function testAddUniqueField($fieldNameType, $expectedResult): void
    {
        $this->_model->addUniqueField($fieldNameType);
        $this->assertEquals($expectedResult, $this->_model->getUniqueFields());
    }

    /**
     * @return array
     */
    public function addUniqueFieldDataProvider(): array
    {
        return [
            [
                'fieldNameString',
                ['fieldNameString']
            ],
            [
                [
                    'fieldNameArray',
                    'FieldNameArraySecond'
                ],
                [
                    [
                        'fieldNameArray',
                        'FieldNameArraySecond'
                    ]
                ]
            ],
            [
                null,
                [null]
            ]
        ];
    }

    /**
     * @return void
     */
    public function testAddUniqueFieldArray(): void
    {
        $this->assertInstanceOf(
            AbstractDb::class,
            $this->_model->addUniqueField(['someField'])
        );
    }

    /**
     * @return void
     */
    public function testGetIdFieldNameException(): void
    {
        $this->expectException('Magento\Framework\Exception\LocalizedException');
        $this->expectExceptionMessage('Empty identifier field name');
        $this->_model->getIdFieldName();
    }

    /**
     * @return void
     */
    public function testGetIdFieldname(): void
    {
        $data = 'MainTableName';
        $idFieldNameProperty = new ReflectionProperty(
            AbstractDb::class,
            '_idFieldName'
        );
        $idFieldNameProperty->setAccessible(true);
        $idFieldNameProperty->setValue($this->_model, $data);
        $this->assertEquals($data, $this->_model->getIdFieldName());
    }

    /**
     * @return void
     */
    public function testGetMainTableException(): void
    {
        $this->expectException('Magento\Framework\Exception\LocalizedException');
        $this->expectExceptionMessage('Empty main table name');
        $this->_model->getMainTable();
    }

    /**
     * @param $tableName
     * @param $expectedResult
     *
     * @return void
     * @dataProvider getTableDataProvider
     */
    public function testGetMainTable($tableName, $expectedResult): void
    {
        $mainTableProperty = new ReflectionProperty(
            AbstractDb::class,
            '_mainTable'
        );
        $mainTableProperty->setAccessible(true);
        $mainTableProperty->setValue($this->_model, $tableName);
        $this->_resourcesMock->expects($this->once())
            ->method('getTableName')
            ->with($expectedResult)
            ->willReturn($expectedResult);
        $this->assertEquals($expectedResult, $this->_model->getMainTable());
    }

    /**
     * @return array
     */
    public function getTableDataProvider(): array
    {
        return [
            [
                'tableName',
                'tableName'
            ],
            [
                [
                    'tableName',
                    'entity_suffix'
                ],
                'tableName_entity_suffix'
            ]
        ];
    }

    /**
     * @return void
     */
    public function testGetTable(): void
    {
        $data = 'tableName';
        $this->_resourcesMock->expects($this->once())->method('getTableName')->with($data)->willReturn(
            'tableName'
        );
        $tablesProperty = new ReflectionProperty(
            AbstractDb::class,
            '_tables'
        );
        $tablesProperty->setAccessible(true);
        $tablesProperty->setValue($this->_model, [$data]);
        $this->assertEquals($data, $this->_model->getTable($data));
    }

    /**
     * @return void
     */
    public function testGetChecksumNegative(): void
    {
        $this->assertFalse($this->_model->getChecksum(null));
    }

    /**
     * @param $checksum
     * @param $expected
     *
     * @return void
     * @dataProvider getChecksumProvider
     */
    public function testGetChecksum($checksum, $expected): void
    {
        $connectionMock = $this->getMockForAbstractClass(AdapterInterface::class);
        $connectionMock->expects($this->once())->method('getTablesChecksum')->with($checksum)->willReturn(
            [$checksum => 'checksum']
        );
        $this->_resourcesMock->expects($this->any())->method('getConnection')->willReturn(
            $connectionMock
        );
        $this->assertEquals($expected, $this->_model->getChecksum($checksum));
    }

    /**
     * @return array
     */
    public function getChecksumProvider(): array
    {
        return [
            [
                'checksum',
                'checksum',
            ],
            [
                14,
                'checksum'
            ]
        ];
    }

    /**
     * @return void
     */
    public function testResetUniqueField(): void
    {
        $uniqueFields = new ReflectionProperty(
            AbstractDb::class,
            '_uniqueFields'
        );
        $uniqueFields->setAccessible(true);
        $uniqueFields->setValue($this->_model, ['uniqueField1', 'uniqueField2']);
        $this->_model->resetUniqueField();
        $this->assertEquals([], $this->_model->getUniqueFields());
    }

    /**
     * @return void
     */
    public function testGetUniqueFields(): void
    {
        $uniqueFieldsReflection = new ReflectionProperty(
            AbstractDb::class,
            '_uniqueFields'
        );
        $uniqueFieldsReflection->setAccessible(true);
        $uniqueFieldsReflection->setValue($this->_model, null);
        $this->assertEquals([], $this->_model->getUniqueFields());
    }

    /**
     * @return void
     */
    public function testGetValidationRulesBeforeSave(): void
    {
        $this->assertNull($this->_model->getValidationRulesBeforeSave());
    }

    /**
     * @return void
     */
    public function testLoad(): void
    {
        /** @var AbstractModel|MockObject $object */
        $object = $this->getMockBuilder(AbstractModel::class)
            ->disableOriginalConstructor()
            ->getMock();
        $object->expects($this->once())->method('beforeLoad')->with('some_value', 'field_name');
        $object->expects($this->once())->method('afterLoad')->willReturnSelf();
        $object->expects($this->once())->method('setOrigData')->willReturnSelf();
        $object->expects($this->once())->method('setHasDataChanges')->with(false)->willReturnSelf();
        $result = $this->_model->load($object, 'some_value', 'field_name');
        $this->assertEquals($this->_model, $result);
        $this->assertInstanceOf(
            AbstractDb::class,
            $result
        );
    }

    /**
     * @return void
     */
    public function testDelete(): void
    {
        $connectionInterfaceMock = $this->getMockForAbstractClass(AdapterInterface::class);
        $contextMock = $this->createMock(\Magento\Framework\Model\Context::class);
        $registryMock = $this->createMock(Registry::class);
        $abstractModelMock = $this->getMockForAbstractClass(
            AbstractModel::class,
            [$contextMock, $registryMock],
            '',
            false,
            true,
            true,
            ['__wakeup', 'getId', 'beforeDelete', 'afterDelete', 'afterDeleteCommit', 'getData']
        );
        $this->_resourcesMock->expects($this->any())
            ->method('getConnection')
            ->willReturn($connectionInterfaceMock);

        $abstractModelMock->expects($this->atLeastOnce())->method('getId')->willReturn(1);
        $abstractModelMock->expects($this->once())->method('getData')->willReturn(['data' => 'value']);
        $connectionMock = $this->getMockForAbstractClass(AdapterInterface::class);
        $this->transactionManagerMock->expects($this->once())
            ->method('start')
            ->with($connectionInterfaceMock)
            ->willReturn($connectionMock);

        $this->relationProcessorMock->expects($this->once())
            ->method('delete')
            ->with(
                $this->transactionManagerMock,
                $connectionMock,
                'tableName',
                'idFieldName',
                ['data' => 'value']
            );

        $this->transactionManagerMock->expects($this->once())->method('commit');

        $data = 'tableName';
        $this->_resourcesMock->expects($this->any())->method('getTableName')->with($data)->willReturn(
            'tableName'
        );
        $mainTableReflection = new ReflectionProperty(
            AbstractDb::class,
            '_mainTable'
        );
        $mainTableReflection->setAccessible(true);
        $mainTableReflection->setValue($this->_model, 'tableName');
        $idFieldNameReflection = new ReflectionProperty(
            AbstractDb::class,
            '_idFieldName'
        );
        $idFieldNameReflection->setAccessible(true);
        $idFieldNameReflection->setValue($this->_model, 'idFieldName');
        $connectionInterfaceMock->expects($this->any())->method('delete')->with('tableName', 'idFieldName');
        $connectionInterfaceMock->expects($this->any())->method('quoteInto')->willReturn('idFieldName');
        $abstractModelMock->expects($this->once())->method('beforeDelete');
        $abstractModelMock->expects($this->once())->method('afterDelete');
        $abstractModelMock->expects($this->once())->method('afterDeleteCommit');
        $this->assertInstanceOf(
            AbstractDb::class,
            $this->_model->delete($abstractModelMock)
        );
    }

    /**
     * @return void
     */
    public function testHasDataChangedNegative(): void
    {
        $contextMock = $this->createMock(\Magento\Framework\Model\Context::class);
        $registryMock = $this->createMock(Registry::class);
        $abstractModelMock = $this->getMockForAbstractClass(
            AbstractModel::class,
            [$contextMock, $registryMock],
            '',
            false,
            true,
            true,
            ['__wakeup', 'getOrigData']
        );
        $abstractModelMock->expects($this->any())->method('getOrigData')->willReturn(false);
        $this->assertTrue($this->_model->hasDataChanged($abstractModelMock));
    }

    /**
     * @param string $getOriginData
     * @param bool $expected
     *
     * @return void
     * @dataProvider hasDataChangedDataProvider
     */
    public function testGetDataChanged($getOriginData, $expected): void
    {
        $connectionInterfaceMock = $this->getMockForAbstractClass(AdapterInterface::class);
        $this->_resourcesMock->expects($this->any())->method('getConnection')->willReturn(
            $connectionInterfaceMock
        );
        $contextMock = $this->createMock(\Magento\Framework\Model\Context::class);
        $registryMock = $this->createMock(Registry::class);
        $abstractModelMock = $this->getMockForAbstractClass(
            AbstractModel::class,
            [$contextMock, $registryMock],
            '',
            false,
            true,
            true,
            ['__wakeup', 'getOrigData', 'getData']
        );
        $mainTableProperty = new ReflectionProperty(
            AbstractDb::class,
            '_mainTable'
        );
        $mainTableProperty->setAccessible(true);
        $mainTableProperty->setValue($this->_model, 'table');

        $this->_resourcesMock->expects($this->once())
            ->method('getTableName')
            ->with('table')
            ->willReturn('tableName');
        $abstractModelMock
            ->method('getOrigData')
            ->willReturnOnConsecutiveCalls(true, $getOriginData);
        $connectionInterfaceMock->expects($this->any())->method('describeTable')->with('tableName')->willReturn(
            ['tableName']
        );
        $this->assertEquals($expected, $this->_model->hasDataChanged($abstractModelMock));
    }

    /**
     * @return array
     */
    public function hasDataChangedDataProvider(): array
    {
        return [
            [true, true],
            [null, false]
        ];
    }

    /**
     * @return void
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function testPrepareDataForUpdate(): void
    {
        $connectionMock = $this->getMockBuilder(AdapterInterface::class)
            ->addMethods(['save'])
            ->getMockForAbstractClass();

        $context = (new ObjectManager($this))->getObject(
            \Magento\Framework\Model\Context::class
        );
        $registryMock = $this->createMock(Registry::class);
        $resourceMock = $this->createPartialMock(
            AbstractDb::class,
            ['_construct', 'getConnection', '__wakeup', 'getIdFieldName']
        );
        $connectionInterfaceMock = $this->getMockForAbstractClass(AdapterInterface::class);
        $resourceMock->expects($this->any())
            ->method('getConnection')
            ->willReturn($connectionInterfaceMock);
        $resourceCollectionMock = $this->getMockBuilder(\Magento\Framework\Data\Collection\AbstractDb::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $abstractModelMock = $this->getMockForAbstractClass(
            AbstractModel::class,
            [$context, $registryMock, $resourceMock, $resourceCollectionMock]
        );
        $data = 'tableName';
        $this->_resourcesMock->expects($this->any())
            ->method('getConnection')
            ->willReturn($connectionMock);
        $this->_resourcesMock->expects($this->any())->method('getTableName')->with($data)->willReturn(
            'tableName'
        );

        $mainTableReflection = new ReflectionProperty(
            AbstractDb::class,
            '_mainTable'
        );
        $mainTableReflection->setAccessible(true);
        $mainTableReflection->setValue($this->_model, 'tableName');
        $idFieldNameReflection = new ReflectionProperty(
            AbstractDb::class,
            '_idFieldName'
        );
        $idFieldNameReflection->setAccessible(true);
        $idFieldNameReflection->setValue($this->_model, 'idFieldName');
        $connectionMock->expects($this->any())->method('save')->with('tableName', 'idFieldName');
        $connectionMock->expects($this->any())->method('quoteInto')->willReturn('idFieldName');
        $connectionMock->expects($this->any())
            ->method('describeTable')
            ->with('tableName')
            ->willReturn(['idFieldName' => []]);
        $connectionMock->expects($this->any())
            ->method('prepareColumnValue')
            ->willReturn(0);
        $abstractModelMock->setIdFieldName('id');
        $abstractModelMock->setData(
            [
                'id'    => 0,
                'name'  => 'Test Name',
                'value' => 'Test Value'
            ]
        );
        $abstractModelMock->afterLoad();
        $this->assertEquals($abstractModelMock->getData(), $abstractModelMock->getStoredData());
        $newData = ['value' => 'Test Value New'];
        $this->_model->expects($this->atLeastOnce())
            ->method('_prepareDataForTable')
            ->willReturn($newData);
        $abstractModelMock->addData($newData);
        $this->assertNotEquals($abstractModelMock->getData(), $abstractModelMock->getStoredData());
        $abstractModelMock->isObjectNew(false);
        $connectionMock->expects($this->once())
            ->method('update')
            ->with(
                'tableName',
                $newData,
                'idFieldName'
            );
        $select = $this->getMockBuilder(Select::class)
            ->disableOriginalConstructor()
            ->getMock();
        $select->expects($this->once())
            ->method('from')
            ->with('tableName')
            ->willReturnSelf();
        $connectionMock->expects($this->once())
            ->method('select')
            ->willReturn($select);
        $select->expects($this->once())
            ->method('reset')
            ->with(Select::WHERE);
        $select->expects($this->exactly(2))
            ->method('where')
            ->withConsecutive(['uniqueField IS NULL'], ['idFieldName!=?', 0]);
        $this->_model->addUniqueField(['field' => 'uniqueField']);
        $this->_model->save($abstractModelMock);
    }

    /**
     * Test that we only set/override id on object if PK autoincrement is enabled.
     *
     * @param bool $pkIncrement
     *
     * @return void
     * @dataProvider saveNewObjectDataProvider
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function testSaveNewObject($pkIncrement): void
    {
        /**
         * Mock SUT so as not to test extraneous logic
         */
        $model = $this->getMockBuilder(AbstractDb::class)->disableOriginalConstructor()
            ->onlyMethods(['_prepareDataForSave', 'getIdFieldName', 'getConnection', 'getMainTable'])
            ->getMockForAbstractClass();
        /**
         * Only testing the logic in a protected method and property, must use reflection to avoid dealing with large
         * amounts of unrelated logic in save function
         *
         * make saveNewObject and _isPkAutoIncrement public
         */
        $reflectionMethod = new \ReflectionMethod($model, 'saveNewObject');
        $reflectionMethod->setAccessible(true);
        $reflectionProperty = new ReflectionProperty($model, '_isPkAutoIncrement');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($model, $pkIncrement);

        // Mocked behavior
        $connectionMock = $this->getMockBuilder(AdapterInterface::class)->disableOriginalConstructor()
            ->addMethods(['lastInsertId'])
            ->getMockForAbstractClass();
        $getConnectionInvokedCount = $pkIncrement ? 2 : 1;
        $model->expects($this->exactly($getConnectionInvokedCount))
            ->method('getConnection')
            ->willReturn($connectionMock);

        $idFieldName = 'id_field_name';
        $model->expects($this->once())->method('_prepareDataForSave')->willReturn([$idFieldName => 'id']);

        // Test expectations
        //      Only get object's id field name if not PK autoincrement
        $getIdFieldNameInvokedCount = $pkIncrement ? 1 : 0;
        $model->expects($this->exactly($getIdFieldNameInvokedCount))
            ->method('getIdFieldName')
            ->willReturn($idFieldName);

        //      Only set object id if not PK autoincrement
        $setIdInvokedCount = $pkIncrement ? 1 : 0;
        $inputObject = $this->getMockBuilder(AbstractModel::class)
            ->disableOriginalConstructor()
            ->getMock();
        $inputObject->expects($this->exactly($setIdInvokedCount))->method('setId');

        //      Only call lastInsertId if not PK autoincrement
        $lastInsertIdInvokedCount = $pkIncrement ? 1 : 0;
        $connectionMock->expects($this->exactly($lastInsertIdInvokedCount))->method('lastInsertId');

        $reflectionMethod->invokeArgs($model, [$inputObject]);
    }

    /**
     * @return array
     */
    public function saveNewObjectDataProvider(): array
    {
        return [[true], [false]];
    }

    /**
     * @return void
     */
    public function testDuplicateExceptionProcessingOnSave(): void
    {
        $this->expectException('Magento\Framework\Exception\AlreadyExistsException');
        $connection = $this->getMockForAbstractClass(AdapterInterface::class);
        $connection->expects($this->once())->method('rollback');

        /** @var AbstractDb|MockObject $model */
        $model = $this->getMockBuilder(AbstractDb::class)->disableOriginalConstructor()
            ->onlyMethods(['getConnection'])
            ->getMockForAbstractClass();
        $model->expects($this->any())->method('getConnection')->willReturn($connection);

        /** @var AbstractModel|MockObject $object */
        $object = $this->getMockBuilder(AbstractModel::class)
            ->disableOriginalConstructor()
            ->getMock();
        $object->expects($this->once())->method('hasDataChanges')->willReturn(true);
        $object->expects($this->once())->method('beforeSave')->willThrowException(new DuplicateException());
        $object->expects($this->once())->method('setHasDataChanges')->with(true);

        $model->save($object);
    }
}
