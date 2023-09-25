<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\Mview\Test\Unit\Config\Data;

use \Magento\Framework\Mview\Config\Data\Proxy;

class ProxyTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\Mview\Config\Data\Proxy
     */
    protected $model;

    /**
     * @var \Magento\Framework\ObjectManagerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $objectManagerMock;

    /**
     * @var \Magento\Framework\Mview\Config\Data|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $dataMock;

    protected function setUp(): void
    {
        $this->objectManagerMock = $this->createMock(\Magento\Framework\ObjectManagerInterface::class);
        $this->dataMock = $this->createMock(\Magento\Framework\Mview\Config\Data::class);
    }

    public function testMergeShared()
    {
        $this->objectManagerMock->expects($this->once())
            ->method('get')
            ->with(\Magento\Framework\Mview\Config\Data::class)
            ->willReturn($this->dataMock);
        $this->dataMock->expects($this->once())
            ->method('merge')
            ->with(['some_config']);

        $this->model = new Proxy(
            $this->objectManagerMock,
            \Magento\Framework\Mview\Config\Data::class,
            true
        );

        $this->model->merge(['some_config']);
    }

    public function testMergeNonShared()
    {
        $this->objectManagerMock->expects($this->once())
            ->method('create')
            ->with(\Magento\Framework\Mview\Config\Data::class)
            ->willReturn($this->dataMock);
        $this->dataMock->expects($this->once())
            ->method('merge')
            ->with(['some_config']);

        $this->model = new Proxy(
            $this->objectManagerMock,
            \Magento\Framework\Mview\Config\Data::class,
            false
        );

        $this->model->merge(['some_config']);
    }

    public function testGetShared()
    {
        $this->objectManagerMock->expects($this->once())
            ->method('get')
            ->with(\Magento\Framework\Mview\Config\Data::class)
            ->willReturn($this->dataMock);
        $this->dataMock->expects($this->once())
            ->method('get')
            ->with('some_path', 'default')
            ->willReturn('some_value');

        $this->model = new Proxy(
            $this->objectManagerMock,
            \Magento\Framework\Mview\Config\Data::class,
            true
        );

        $this->assertEquals('some_value', $this->model->get('some_path', 'default'));
    }

    public function testGetNonShared()
    {
        $this->objectManagerMock->expects($this->once())
            ->method('create')
            ->with(\Magento\Framework\Mview\Config\Data::class)
            ->willReturn($this->dataMock);
        $this->dataMock->expects($this->once())
            ->method('get')
            ->with('some_path', 'default')
            ->willReturn('some_value');

        $this->model = new Proxy(
            $this->objectManagerMock,
            \Magento\Framework\Mview\Config\Data::class,
            false
        );

        $this->assertEquals('some_value', $this->model->get('some_path', 'default'));
    }
}
