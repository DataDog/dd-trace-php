<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Authorization\Test\Unit\Policy;

class AclTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\Authorization\Policy\Acl
     */
    protected $_model;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $_aclMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $_aclBuilderMock;

    protected function setUp(): void
    {
        $this->_aclMock = $this->createMock(\Magento\Framework\Acl::class);
        $this->_aclBuilderMock = $this->createMock(\Magento\Framework\Acl\Builder::class);
        $this->_aclBuilderMock->expects($this->any())->method('getAcl')->willReturn($this->_aclMock);
        $this->_model = new \Magento\Framework\Authorization\Policy\Acl($this->_aclBuilderMock);
    }

    public function testIsAllowedReturnsTrueIfResourceIsAllowedToRole()
    {
        $this->_aclMock->expects(
            $this->once()
        )->method(
            'isAllowed'
        )->with(
            'some_role',
            'some_resource'
        )->willReturn(
            true
        );

        $this->assertTrue($this->_model->isAllowed('some_role', 'some_resource'));
    }

    public function testIsAllowedReturnsFalseIfRoleDoesntExist()
    {
        $this->_aclMock->expects(
            $this->once()
        )->method(
            'isAllowed'
        )->with(
            'some_role',
            'some_resource'
        )->will(
            $this->throwException(new \Zend_Acl_Role_Registry_Exception())
        );

        $this->_aclMock->expects($this->once())->method('has')->with('some_resource')->willReturn(true);

        $this->assertFalse($this->_model->isAllowed('some_role', 'some_resource'));
    }

    public function testIsAllowedReturnsTrueIfResourceDoesntExistAndAllResourcesAreNotPermitted()
    {
        $this->_aclMock->expects(
            $this->at(0)
        )->method(
            'isAllowed'
        )->with(
            'some_role',
            'some_resource'
        )->will(
            $this->throwException(new \Zend_Acl_Role_Registry_Exception())
        );

        $this->_aclMock->expects($this->once())->method('has')->with('some_resource')->willReturn(false);

        $this->_aclMock->expects(
            $this->at(2)
        )->method(
            'isAllowed'
        )->with(
            'some_role',
            null
        )->willReturn(
            true
        );

        $this->assertTrue($this->_model->isAllowed('some_role', 'some_resource'));
    }
}
