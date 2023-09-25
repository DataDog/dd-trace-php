<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Backend\Test\Unit\App\Router;

use Magento\Backend\App\Router\NoRouteHandler;
use Magento\Backend\Helper\Data;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Route\ConfigInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class NoRouteHandlerTest extends TestCase
{
    /**
     * @var MockObject
     */
    protected $_helperMock;

    /**
     * @var MockObject
     */
    protected $_requestMock;

    /**
     * @var MockObject
     */
    protected $_routeConfigMock;

    /**
     * @var NoRouteHandler
     */
    protected $_model;

    protected function setUp(): void
    {
        $this->_requestMock = $this->createMock(Http::class);
        $this->_routeConfigMock = $this->getMockForAbstractClass(ConfigInterface::class);
        $this->_helperMock = $this->createMock(Data::class);
        $this->_helperMock->expects($this->any())->method('getAreaFrontName')->willReturn('backend');
        $this->_model = new NoRouteHandler($this->_helperMock, $this->_routeConfigMock);
    }

    /**
     * @covers \Magento\Backend\App\Router\NoRouteHandler::process
     */
    public function testProcessWithBackendAreaFrontName()
    {
        $this->_routeConfigMock
            ->expects($this->once())
            ->method('getRouteFrontName')
            ->with('adminhtml')
            ->willReturn('admin');

        $this->_requestMock
            ->expects($this->once())
            ->method('getPathInfo')
            ->willReturn('backend/admin/custom');

        $this->_requestMock->expects(
            $this->once()
        )->method(
            'setModuleName'
        )->with(
            'admin'
        )->willReturn(
            $this->_requestMock
        );

        $this->_requestMock->expects(
            $this->once()
        )->method(
            'setControllerName'
        )->with(
            'noroute'
        )->willReturn(
            $this->_requestMock
        );

        $this->_requestMock->expects(
            $this->once()
        )->method(
            'setActionName'
        )->with(
            'index'
        )->willReturn(
            $this->_requestMock
        );

        $this->assertTrue($this->_model->process($this->_requestMock));
    }

    /**
     * @covers \Magento\Backend\App\Router\NoRouteHandler::process
     */
    public function testProcessWithoutAreaFrontName()
    {
        $this->_requestMock->expects(
            $this->once()
        )->method(
            'getPathInfo'
        )->willReturn(
            'module/controller/action'
        );

        $this->_requestMock->expects($this->never())->method('setModuleName');

        $this->_requestMock->expects($this->never())->method('setControllerName');

        $this->_requestMock->expects($this->never())->method('setActionName');

        $this->assertFalse($this->_model->process($this->_requestMock));
    }
}
