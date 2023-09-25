<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Interception\Test\Unit\ObjectManager\Config;

use \Magento\Framework\Interception\ObjectManager\Config\Developer;

class DeveloperTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\Interception\ObjectManager\Config\Developer
     */
    private $model;

    /**
     * @var \Magento\Framework\Interception\ConfigInterface | \PHPUnit\Framework\MockObject\MockObject
     */
    private $interceptionConfig;

    protected function setUp(): void
    {
        $this->interceptionConfig = $this->createMock(\Magento\Framework\Interception\ConfigInterface::class);
        $this->model = new Developer();
    }

    public function testGetInstanceTypeReturnsInterceptorClass()
    {
        $this->interceptionConfig->expects($this->once())->method('hasPlugins')->willReturn(true);
        $this->model->setInterceptionConfig($this->interceptionConfig);

        $this->assertEquals('SomeClass\Interceptor', $this->model->getInstanceType('SomeClass'));
    }

    public function testGetInstanceTypeReturnsSimpleClassIfNoPluginsAreDeclared()
    {
        $this->model->setInterceptionConfig($this->interceptionConfig);

        $this->assertEquals('SomeClass', $this->model->getInstanceType('SomeClass'));
    }

    public function testGetInstanceTypeReturnsSimpleClassIfInterceptionConfigIsNotSet()
    {
        $this->assertEquals('SomeClass', $this->model->getInstanceType('SomeClass'));
    }

    public function testGetOriginalInstanceTypeReturnsInterceptedClass()
    {
        $this->interceptionConfig->expects($this->once())->method('hasPlugins')->willReturn(true);
        $this->model->setInterceptionConfig($this->interceptionConfig);

        $this->assertEquals('SomeClass\Interceptor', $this->model->getInstanceType('SomeClass'));
        $this->assertEquals('SomeClass', $this->model->getOriginalInstanceType('SomeClass'));
    }
}
