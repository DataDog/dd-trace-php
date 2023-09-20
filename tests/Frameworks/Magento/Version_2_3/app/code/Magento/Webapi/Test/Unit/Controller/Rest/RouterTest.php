<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Webapi\Test\Unit\Controller\Rest;

class RouterTest extends \PHPUnit\Framework\TestCase
{
    /** @var \Magento\Webapi\Controller\Rest\Router\Route */
    protected $_routeMock;

    /** @var \Magento\Framework\Webapi\Rest\Request */
    protected $_request;

    /** @var \Magento\Webapi\Model\Rest\Config */
    protected $_apiConfigMock;

    /** @var \Magento\Webapi\Controller\Rest\Router */
    protected $_router;

    protected function setUp(): void
    {
        /** Prepare mocks for SUT constructor. */
        $this->_apiConfigMock = $this->getMockBuilder(
            \Magento\Webapi\Model\Rest\Config::class
        )->disableOriginalConstructor()->getMock();

        $this->_routeMock = $this->getMockBuilder(
            \Magento\Webapi\Controller\Rest\Router\Route::class
        )->disableOriginalConstructor()->setMethods(
            ['match']
        )->getMock();

        $areaListMock = $this->createMock(\Magento\Framework\App\AreaList::class);

        $areaListMock->expects($this->once())
            ->method('getFrontName')
            ->willReturn('rest');

        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->_request = $objectManager->getObject(
            \Magento\Framework\Webapi\Rest\Request::class,
            [
                'areaList' => $areaListMock,
            ]
        );

        /** Initialize SUT. */
        $this->_router = $objectManager->getObject(
            \Magento\Webapi\Controller\Rest\Router::class,
            [
                'apiConfig' => $this->_apiConfigMock
            ]
        );
    }

    protected function tearDown(): void
    {
        unset($this->_routeMock);
        unset($this->_request);
        unset($this->_apiConfigMock);
        unset($this->_router);
        parent::tearDown();
    }

    public function testMatch()
    {
        $this->_apiConfigMock->expects(
            $this->once()
        )->method(
            'getRestRoutes'
        )->willReturn(
            [$this->_routeMock]
        );
        $this->_routeMock->expects(
            $this->once()
        )->method(
            'match'
        )->with(
            $this->_request
        )->willReturn(
            []
        );

        $matchedRoute = $this->_router->match($this->_request);
        $this->assertEquals($this->_routeMock, $matchedRoute);
    }

    /**
     */
    public function testNotMatch()
    {
        $this->expectException(\Magento\Framework\Webapi\Exception::class);

        $this->_apiConfigMock->expects(
            $this->once()
        )->method(
            'getRestRoutes'
        )->willReturn(
            [$this->_routeMock]
        );
        $this->_routeMock->expects(
            $this->once()
        )->method(
            'match'
        )->with(
            $this->_request
        )->willReturn(
            false
        );

        $this->_router->match($this->_request);
    }
}
