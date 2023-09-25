<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\Model\Test\Unit;

class AbstractModelTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\Model\AbstractModel
     */
    protected $model;

    /**
     * @var \Magento\Framework\Model\Context|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $contextMock;

    /**
     * @var \Magento\Framework\Registry|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $registryMock;

    /**
     * @var \Magento\Framework\Model\ResourceModel\Db\AbstractDb|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $resourceMock;

    /**
     * @var \Magento\Framework\Data\Collection\AbstractDb|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $resourceCollectionMock;

    /**
     * @var \Magento\Framework\DB\Adapter\AdapterInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $connectionMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $actionValidatorMock;

    protected function setUp(): void
    {
        $this->actionValidatorMock = $this->createMock(\Magento\Framework\Model\ActionValidator\RemoveAction::class);
        $this->contextMock = new \Magento\Framework\Model\Context(
            $this->createMock(\Psr\Log\LoggerInterface::class),
            $this->createMock(\Magento\Framework\Event\ManagerInterface::class),
            $this->createMock(\Magento\Framework\App\CacheInterface::class),
            $this->createMock(\Magento\Framework\App\State::class),
            $this->actionValidatorMock
        );
        $this->registryMock = $this->createMock(\Magento\Framework\Registry::class);
        $this->resourceMock = $this->createPartialMock(\Magento\Framework\Model\ResourceModel\Db\AbstractDb::class, [
                '_construct',
                'getConnection',
                '__wakeup',
                'commit',
                'delete',
                'getIdFieldName',
                'rollBack'
            ]);
        $this->resourceCollectionMock = $this->getMockBuilder(\Magento\Framework\Data\Collection\AbstractDb::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $this->model = $this->getMockForAbstractClass(
            \Magento\Framework\Model\AbstractModel::class,
            [$this->contextMock, $this->registryMock, $this->resourceMock, $this->resourceCollectionMock]
        );
        $this->connectionMock = $this->createMock(\Magento\Framework\DB\Adapter\AdapterInterface::class);
        $this->resourceMock->expects($this->any())
            ->method('getConnection')
            ->willReturn($this->connectionMock);
    }

    public function testDelete()
    {
        $this->resourceMock->expects($this->once())->method('delete')->with($this->model);
        $this->model->delete();
    }

    public function testUpdateStoredData()
    {
        $this->model->setData(
            [
                'id'   => 1000,
                'name' => 'Test Name'
            ]
        );
        $this->assertEmpty($this->model->getStoredData());
        $this->model->afterLoad();
        $this->assertEquals($this->model->getData(), $this->model->getStoredData());
        $this->model->setData('value', 'Test Value');
        $this->model->afterSave();
        $this->assertEquals($this->model->getData(), $this->model->getStoredData());
        $this->model->afterDelete();
        $this->assertEmpty($this->model->getStoredData());
    }

    /**
     * Tests \Magento\Framework\DataObject->isDeleted()
     */
    public function testIsDeleted()
    {
        $this->assertFalse($this->model->isDeleted());
        $this->model->isDeleted();
        $this->assertFalse($this->model->isDeleted());
        $this->model->isDeleted(true);
        $this->assertTrue($this->model->isDeleted());
    }

    /**
     * Tests \Magento\Framework\DataObject->hasDataChanges()
     */
    public function testHasDataChanges()
    {
        $this->assertFalse($this->model->hasDataChanges());
        $this->model->setData('key', 'value');
        $this->assertTrue($this->model->hasDataChanges(), 'Data changed');

        $this->model->setDataChanges(false);
        $this->model->setData('key', 'value');
        $this->assertFalse($this->model->hasDataChanges(), 'Data not changed');

        $this->model->setData(['key' => 'value']);
        $this->assertFalse($this->model->hasDataChanges(), 'Data not changed (array)');

        $this->model->unsetData();
        $this->assertTrue($this->model->hasDataChanges(), 'Unset data');
    }

    /**
     * Tests \Magento\Framework\DataObject->getId()
     */
    public function testSetGetId()
    {
        $this->model->setId('test');
        $this->assertEquals('test', $this->model->getId());
    }

    public function testSetGetIdFieldName()
    {
        $name = 'entity_id_custom';
        $this->model->setIdFieldName($name);
        $this->assertEquals($name, $this->model->getIdFieldName());
    }

    /**
     * Tests \Magento\Framework\DataObject->setOrigData()
     */
    public function testOrigData()
    {
        $data = ['key1' => 'value1', 'key2' => 'value2'];
        $this->model->setData($data);
        $this->model->setOrigData();
        $this->model->setData('key1', 'test');
        $this->assertTrue($this->model->dataHasChangedFor('key1'));
        $this->assertEquals($data, $this->model->getOrigData());

        $this->model->setOrigData('key1', 'test');
        $this->assertEquals('test', $this->model->getOrigData('key1'));
    }

    /**
     * Tests \Magento\Framework\DataObject->setDataChanges()
     */
    public function testSetDataChanges()
    {
        $this->assertFalse($this->model->hasDataChanges());
        $this->model->setDataChanges(true);
        $this->assertTrue($this->model->hasDataChanges());
    }
}
