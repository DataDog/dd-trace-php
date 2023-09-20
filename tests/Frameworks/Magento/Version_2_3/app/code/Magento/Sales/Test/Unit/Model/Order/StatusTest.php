<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Sales\Test\Unit\Model\Order;

/**
 * Class StatusTest
 *
 * @package Magento\Sales\Model\Order
 */
class StatusTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Sales\Model\ResourceModel\Order\Status|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $resourceMock;

    /**
     * @var \Magento\Framework\Event\Manager|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $eventManagerMock;

    /**
     * @var \Magento\Framework\Model\Context|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $contextMock;

    /**
     * @var \Magento\Sales\Model\Order\Status
     */
    protected $model;

    /**
     * SetUp test
     */
    protected function setUp(): void
    {
        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->resourceMock = $this->createMock(\Magento\Sales\Model\ResourceModel\Order\Status::class);
        $this->eventManagerMock = $this->createMock(\Magento\Framework\Event\Manager::class);
        $this->contextMock = $this->createMock(\Magento\Framework\Model\Context::class);
        $this->contextMock->expects($this->once())
            ->method('getEventDispatcher')
            ->willReturn($this->eventManagerMock);

        $this->model = $objectManager->getObject(
            \Magento\Sales\Model\Order\Status::class,
            [
                'context' => $this->contextMock,
                'resource' => $this->resourceMock,
                'data' => ['status' => 'test_status']
            ]
        );
    }

    /**
     *  Test for method unassignState
     */
    public function testUnassignStateSuccess()
    {
        $params = [
            'status' => $this->model->getStatus(),
            'state' => 'test_state',
        ];
        $this->resourceMock->expects($this->once())
            ->method('checkIsStateLast')
            ->with($this->equalTo($params['state']))
            ->willReturn(false);
        $this->resourceMock->expects($this->once())
            ->method('checkIsStatusUsed')
            ->with($this->equalTo($params['status']))
            ->willReturn(false);
        $this->eventManagerMock->expects($this->once())
            ->method('dispatch')
            ->with($this->equalTo('sales_order_status_unassign'), $this->equalTo($params));

        $this->resourceMock->expects($this->once())
            ->method('unassignState')
            ->with($this->equalTo($params['status']), $this->equalTo($params['state']));
        $this->assertEquals($this->model, $this->model->unassignState($params['state']));
    }

    /**
     *  Test for method unassignState state is last
     *
     */
    public function testUnassignStateStateIsLast()
    {
        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);
        $this->expectExceptionMessage('The last status can\'t be changed and needs to stay assigned to its current state.');

        $params = [
            'status' => $this->model->getStatus(),
            'state' => 'test_state',
        ];
        $this->resourceMock->expects($this->once())
            ->method('checkIsStateLast')
            ->with($this->equalTo($params['state']))
            ->willReturn(true);
        $this->assertEquals($this->model, $this->model->unassignState($params['state']));
    }

    /**
     * Test for method unassignState status in use
     *
     */
    public function testUnassignStateStatusUsed()
    {
        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);
        $this->expectExceptionMessage('The status can\'t be unassigned because the status is currently used by an order.');

        $params = [
            'status' => $this->model->getStatus(),
            'state' => 'test_state',
        ];
        $this->resourceMock->expects($this->once())
            ->method('checkIsStateLast')
            ->with($this->equalTo($params['state']))
            ->willReturn(false);
        $this->resourceMock->expects($this->once())
            ->method('checkIsStatusUsed')
            ->with($this->equalTo($params['status']))
            ->willReturn(true);
        $this->assertEquals($this->model, $this->model->unassignState($params['state']));
    }

    /**
     * Retrieve prepared for test \Magento\Sales\Model\Order\Status
     *
     * @param null|\PHPUnit\Framework\MockObject\MockObject $resource
     * @param null|\PHPUnit\Framework\MockObject\MockObject $eventDispatcher
     * @return \Magento\Sales\Model\Order\Status
     */
    protected function _getPreparedModel($resource = null, $eventDispatcher = null)
    {
        if (!$resource) {
            $resource = $this->createMock(\Magento\Sales\Model\ResourceModel\Order\Status::class);
        }
        if (!$eventDispatcher) {
            $eventDispatcher = $this->createMock(\Magento\Framework\Event\ManagerInterface::class);
        }
        $helper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $model = $helper->getObject(
            \Magento\Sales\Model\Order\Status::class,
            ['resource' => $resource, 'eventDispatcher' => $eventDispatcher]
        );
        return $model;
    }

    /**
     * Test for method assignState
     */
    public function testAssignState()
    {
        $state = 'test_state';
        $status = 'test_status';
        $visibleOnFront = true;

        $resource = $this->createMock(\Magento\Sales\Model\ResourceModel\Order\Status::class);
        $resource->expects($this->once())
            ->method('beginTransaction');
        $resource->expects($this->once())
            ->method('assignState')
            ->with(
                $this->equalTo($status),
                $this->equalTo($state)
            );
        $resource->expects($this->once())->method('commit');

        $eventDispatcher = $this->createMock(\Magento\Framework\Event\ManagerInterface::class);

        $model = $this->_getPreparedModel($resource, $eventDispatcher);
        $model->setStatus($status);
        $this->assertInstanceOf(
            \Magento\Sales\Model\Order\Status::class,
            $model->assignState($state),
            $visibleOnFront
        );
    }
}
