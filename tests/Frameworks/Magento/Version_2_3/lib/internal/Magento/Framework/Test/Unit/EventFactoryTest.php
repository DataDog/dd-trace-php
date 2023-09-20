<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Test\Unit;

class EventFactoryTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\EventFactory
     */
    protected $_model;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $_objectManagerMock;

    /**
     * @var \Magento\Framework\Event
     */
    protected $_expectedObject;

    protected function setUp(): void
    {
        $this->_objectManagerMock = $this->createMock(\Magento\Framework\ObjectManagerInterface::class);
        $this->_model = new \Magento\Framework\EventFactory($this->_objectManagerMock);
        $this->_expectedObject = $this->getMockBuilder(\Magento\Framework\Event::class)->getMock();
    }

    public function testCreate()
    {
        $arguments = ['property' => 'value'];
        $this->_objectManagerMock->expects(
            $this->once()
        )->method(
            'create'
        )->with(
            \Magento\Framework\Event::class,
            $arguments
        )->willReturn(
            $this->_expectedObject
        );

        $this->assertEquals($this->_expectedObject, $this->_model->create($arguments));
    }
}
