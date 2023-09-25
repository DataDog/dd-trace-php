<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Catalog\Test\Unit\Model\Product\ProductList;

use Magento\Catalog\Model\Product\ProductList\Toolbar;
use Magento\Framework\App\Request\Http;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;

use PHPUnit\Framework\TestCase;

class ToolbarTest extends TestCase
{
    /**
     * @var Toolbar
     */
    protected $toolbarModel;

    /**
     * @var Http|MockObject
     */
    protected $requestMock;

    /**
     * Set up
     */
    protected function setUp(): void
    {
        $this->requestMock = $this->getMockBuilder(Http::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->toolbarModel = (new ObjectManager($this))->getObject(
            Toolbar::class,
            [
                'request' => $this->requestMock,
            ]
        );
    }

    /**
     * @dataProvider stringParamProvider
     * @param $param
     */
    public function testGetOrder($param)
    {
        $this->requestMock->expects($this->once())
            ->method('getParam')
            ->with(Toolbar::ORDER_PARAM_NAME)
            ->willReturn($param);
        $this->assertEquals($param, $this->toolbarModel->getOrder());
    }

    /**
     * @dataProvider stringParamProvider
     * @param $param
     */
    public function testGetDirection($param)
    {
        $this->requestMock->expects($this->once())
            ->method('getParam')
            ->with(Toolbar::DIRECTION_PARAM_NAME)
            ->willReturn($param);
        $this->assertEquals($param, $this->toolbarModel->getDirection());
    }

    /**
     * @dataProvider stringParamProvider
     * @param $param
     */
    public function testGetMode($param)
    {
        $this->requestMock->expects($this->once())
            ->method('getParam')
            ->with(Toolbar::MODE_PARAM_NAME)
            ->willReturn($param);
        $this->assertEquals($param, $this->toolbarModel->getMode());
    }

    /**
     * @dataProvider stringParamProvider
     * @param $param
     */
    public function testGetLimit($param)
    {
        $this->requestMock->expects($this->once())
            ->method('getParam')
            ->with(Toolbar::LIMIT_PARAM_NAME)
            ->willReturn($param);
        $this->assertEquals($param, $this->toolbarModel->getLimit());
    }

    /**
     * @dataProvider intParamProvider
     * @param $param
     */
    public function testGetCurrentPage($param)
    {
        $this->requestMock->expects($this->once())
            ->method('getParam')
            ->with(Toolbar::PAGE_PARM_NAME)
            ->willReturn($param);
        $this->assertEquals($param, $this->toolbarModel->getCurrentPage());
    }

    public function testGetCurrentPageNoParam()
    {
        $this->requestMock->expects($this->once())
            ->method('getParam')
            ->with(Toolbar::PAGE_PARM_NAME)
            ->willReturn(false);
        $this->assertEquals(1, $this->toolbarModel->getCurrentPage());
    }

    /**
     * @return array
     */
    public function stringParamProvider()
    {
        return [
            ['stringParam']
        ];
    }

    /**
     * @return array
     */
    public function intParamProvider()
    {
        return [
            ['2'],
            [3]
        ];
    }
}
