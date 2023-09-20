<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Sales\Test\Unit\Model;

class ConfigTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Sales\Model\Config
     */
    protected $model;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $configDataMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $stateMock;

    protected function setUp(): void
    {
        $this->configDataMock = $this->getMockBuilder(\Magento\Sales\Model\Config\Data::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->stateMock = $this->getMockBuilder(\Magento\Framework\App\State::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->model = new \Magento\Sales\Model\Config($this->configDataMock, $this->stateMock);
    }

    public function testInstanceOf()
    {
        $model = new \Magento\Sales\Model\Config($this->configDataMock, $this->stateMock);
        $this->assertInstanceOf(\Magento\Sales\Model\Config::class, $model);
    }

    public function testGetTotalsRenderer()
    {
        $areaCode = 'frontend';
        $section = 'config';
        $group = 'sales';
        $code = 'payment';
        $path = $section . '/' . $group . '/' . $code . '/' . 'renderers' . '/' . $areaCode;
        $expected = ['test data'];

        $this->stateMock->expects($this->once())
            ->method('getAreaCode')
            ->willReturn($areaCode);
        $this->configDataMock->expects($this->once())
            ->method('get')
            ->with($this->equalTo($path))
            ->willReturn($expected);

        $result = $this->model->getTotalsRenderer($section, $group, $code);
        $this->assertEquals($expected, $result);
    }

    public function testGetGroupTotals()
    {
        $section = 'config';
        $group = 'payment';
        $expected = ['test data'];
        $path = $section . '/' . $group;

        $this->configDataMock->expects($this->once())
            ->method('get')
            ->with($this->equalTo($path))
            ->willReturn($expected);

        $result = $this->model->getGroupTotals($section, $group);
        $this->assertEquals($expected, $result);
    }

    public function testGetAvailableProductTypes()
    {
        $productTypes = ['simple'];

        $this->configDataMock->expects($this->once())
            ->method('get')
            ->with($this->equalTo('order/available_product_types'))
            ->willReturn($productTypes);
        $result = $this->model->getAvailableProductTypes();
        $this->assertEquals($productTypes, $result);
    }
}
