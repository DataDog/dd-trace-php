<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Module\Test\Unit;

use \Magento\Framework\Module\ModuleList;

class ModuleListTest extends \PHPUnit\Framework\TestCase
{
    /**
     * Fixture for all modules' meta-information
     *
     * @var array
     */
    private static $allFixture = ['foo' => ['key' => 'value'], 'bar' => ['another' => 'value']];

    /**
     * Fixture for enabled modules
     *
     * @var array
     */
    private static $enabledFixture = ['foo' => 1, 'bar' => 0];

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $config;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $loader;

    /**
     * @var ModuleList
     */
    private $model;

    protected function setUp(): void
    {
        $this->config = $this->createMock(\Magento\Framework\App\DeploymentConfig::class);
        $this->loader = $this->createMock(\Magento\Framework\Module\ModuleList\Loader::class);
        $this->model = new ModuleList($this->config, $this->loader);
    }

    public function testGetAll()
    {
        $this->setLoadAllExpectation();
        $this->setLoadConfigExpectation();
        $expected = ['foo' => self::$allFixture['foo']];
        $this->assertSame($expected, $this->model->getAll());
        $this->assertSame($expected, $this->model->getAll()); // second time to ensure loadAll is called once
    }

    public function testGetAllNoData()
    {
        $this->loader->expects($this->exactly(2))->method('load')->willReturn([]);
        $this->setLoadConfigExpectation(false);
        $this->assertEquals([], $this->model->getAll());
        $this->assertEquals([], $this->model->getAll());
    }

    public function testGetOne()
    {
        $this->setLoadAllExpectation();
        $this->setLoadConfigExpectation();
        $this->assertSame(['key' => 'value'], $this->model->getOne('foo'));
        $this->assertNull($this->model->getOne('bar'));
    }

    public function testGetNames()
    {
        $this->setLoadAllExpectation(false);
        $this->setLoadConfigExpectation();
        $this->assertSame(['foo'], $this->model->getNames());
        $this->assertSame(['foo'], $this->model->getNames()); // second time to ensure config loader is called once
    }

    public function testHas()
    {
        $this->setLoadAllExpectation(false);
        $this->setLoadConfigExpectation();
        $this->assertTrue($this->model->has('foo'));
        $this->assertFalse($this->model->has('bar'));
    }

    public function testIsModuleInfoAvailable()
    {
        $this->setLoadConfigExpectation(true);
        $this->assertTrue($this->model->isModuleInfoAvailable());
    }

    public function testIsModuleInfoAvailableNoConfig()
    {
        $this->config->expects($this->at(0))->method('get')->willReturn(['modules' => 'testModule']);
        $this->config->expects($this->at(1))->method('get')->willReturn(null);
        $this->assertFalse($this->model->isModuleInfoAvailable());
    }

    /**
     * Prepares expectation for loading deployment configuration
     *
     * @param bool $isExpected
     * @return void
     */
    private function setLoadConfigExpectation($isExpected = true)
    {
        if ($isExpected) {
            $this->config->expects($this->exactly(2))->method('get')->willReturn(self::$enabledFixture);
        } else {
            $this->config->expects($this->never())->method('get');
        }
    }

    /**
     * Prepares expectation for loading full list of modules
     *
     * @param bool $isExpected
     * @return void
     */
    private function setLoadAllExpectation($isExpected = true)
    {
        if ($isExpected) {
            $this->loader->expects($this->once())->method('load')->willReturn(self::$allFixture);
        } else {
            $this->loader->expects($this->never())->method('load');
        }
    }
}
