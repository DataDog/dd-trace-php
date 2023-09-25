<?php
/**
 * Unit Test for \Magento\Framework\Profiler
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Test\Unit;

class ProfilerTest extends \PHPUnit\Framework\TestCase
{
    protected function tearDown(): void
    {
        \Magento\Framework\Profiler::reset();
    }

    public function testEnable()
    {
        \Magento\Framework\Profiler::enable();
        $this->assertTrue(\Magento\Framework\Profiler::isEnabled());
    }

    public function testDisable()
    {
        \Magento\Framework\Profiler::disable();
        $this->assertFalse(\Magento\Framework\Profiler::isEnabled());
    }

    public function testSetDefaultTags()
    {
        $this->markTestSkipped('Skipped in #27500 due to testing protected/private methods and properties');

        $expected = ['some_key' => 'some_value'];
        \Magento\Framework\Profiler::setDefaultTags($expected);
        //$this->assertAttributeEquals($expected, '_defaultTags', \Magento\Framework\Profiler::class);
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     */
    public function testAddTagFilter()
    {
        $this->markTestSkipped('Skipped in #27500 due to testing protected/private methods and properties');

        \Magento\Framework\Profiler::addTagFilter('tag1', 'value_1.1');
        \Magento\Framework\Profiler::addTagFilter('tag2', 'value_2.1');
        \Magento\Framework\Profiler::addTagFilter('tag1', 'value_1.2');

        $expected = ['tag1' => ['value_1.1', 'value_1.2'], 'tag2' => ['value_2.1']];
        //$this->assertAttributeEquals($expected, '_tagFilters', \Magento\Framework\Profiler::class);
        //$this->assertAttributeEquals(true, '_hasTagFilters', \Magento\Framework\Profiler::class);
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     */
    public function testAdd()
    {
        $this->markTestSkipped('Skipped in #27500 due to testing protected/private methods and properties');

        $mock = $this->_getDriverMock();
        \Magento\Framework\Profiler::add($mock);

        $this->assertTrue(\Magento\Framework\Profiler::isEnabled());

        $expected = [$mock];
        //$this->assertAttributeEquals($expected, '_drivers', \Magento\Framework\Profiler::class);
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    protected function _getDriverMock()
    {
        return $this->getMockBuilder(
            \Magento\Framework\Profiler\DriverInterface::class
        )->setMethods(
            ['start', 'stop', 'clear']
        )->getMockForAbstractClass();
    }

    /**
     */
    public function testStartException()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Timer name must not contain a nesting separator.');

        \Magento\Framework\Profiler::enable();
        \Magento\Framework\Profiler::start('timer ' . \Magento\Framework\Profiler::NESTING_SEPARATOR . ' name');
    }

    public function testDisabledProfiler()
    {
        $driver = $this->_getDriverMock();
        $driver->expects($this->never())->method('start');
        $driver->expects($this->never())->method('stop');

        \Magento\Framework\Profiler::add($driver);
        \Magento\Framework\Profiler::disable();
        \Magento\Framework\Profiler::start('test');
        \Magento\Framework\Profiler::stop('test');
    }

    public function testStartStopSimple()
    {
        $driver = $this->_getDriverMock();
        $driver->expects($this->once())->method('start')->with('root_level_timer', null);
        $driver->expects($this->once())->method('stop')->with('root_level_timer');

        \Magento\Framework\Profiler::add($driver);
        \Magento\Framework\Profiler::start('root_level_timer');
        \Magento\Framework\Profiler::stop('root_level_timer');
    }

    public function testStartNested()
    {
        $driver = $this->_getDriverMock();
        $driver->expects($this->at(0))->method('start')->with('root_level_timer', null);
        $driver->expects($this->at(1))->method('start')->with('root_level_timer->some_other_timer', null);

        $driver->expects($this->at(2))->method('stop')->with('root_level_timer->some_other_timer');
        $driver->expects($this->at(3))->method('stop')->with('root_level_timer');

        \Magento\Framework\Profiler::add($driver);
        \Magento\Framework\Profiler::start('root_level_timer');
        \Magento\Framework\Profiler::start('some_other_timer');
        \Magento\Framework\Profiler::stop('some_other_timer');
        \Magento\Framework\Profiler::stop('root_level_timer');
    }

    /**
     */
    public function testStopExceptionUnknown()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Timer "unknown" has not been started.');

        \Magento\Framework\Profiler::enable();
        \Magento\Framework\Profiler::start('timer');
        \Magento\Framework\Profiler::stop('unknown');
    }

    public function testStopOrder()
    {
        $driver = $this->_getDriverMock();
        $driver->expects($this->at(0))->method('start')->with('timer1', null);
        $driver->expects($this->at(1))->method('start')->with('timer1->timer2', null);
        $driver->expects($this->at(2))->method('start')->with('timer1->timer2->timer1', null);
        $driver->expects($this->at(3))->method('start')->with('timer1->timer2->timer1->timer3', null);

        $driver->expects($this->at(4))->method('stop')->with('timer1->timer2->timer1->timer3');
        $driver->expects($this->at(5))->method('stop')->with('timer1->timer2->timer1');

        $driver->expects($this->exactly(4))->method('start');
        $driver->expects($this->exactly(2))->method('stop');

        \Magento\Framework\Profiler::add($driver);
        \Magento\Framework\Profiler::start('timer1');
        \Magento\Framework\Profiler::start('timer2');
        \Magento\Framework\Profiler::start('timer1');
        \Magento\Framework\Profiler::start('timer3');
        \Magento\Framework\Profiler::stop('timer1');
    }

    public function testStopSameName()
    {
        $driver = $this->_getDriverMock();
        $driver->expects($this->at(0))->method('start')->with('timer1', null);
        $driver->expects($this->at(1))->method('start')->with('timer1->timer1', null);

        $driver->expects($this->at(2))->method('stop')->with('timer1->timer1');
        $driver->expects($this->at(3))->method('stop')->with('timer1');

        \Magento\Framework\Profiler::add($driver);
        \Magento\Framework\Profiler::start('timer1');
        \Magento\Framework\Profiler::start('timer1');
        \Magento\Framework\Profiler::stop('timer1');
        \Magento\Framework\Profiler::stop('timer1');
    }

    public function testStopLatest()
    {
        $driver = $this->_getDriverMock();
        $driver->expects($this->at(0))->method('start')->with('root_level_timer', null);

        $driver->expects($this->at(1))->method('stop')->with('root_level_timer');

        \Magento\Framework\Profiler::add($driver);
        \Magento\Framework\Profiler::start('root_level_timer');
        \Magento\Framework\Profiler::stop();
    }

    public function testTags()
    {
        $driver = $this->_getDriverMock();
        $driver->expects($this->at(0))->method('start')->with('root_level_timer', ['default_tag' => 'default']);
        $driver->expects(
            $this->at(1)
        )->method(
            'start'
        )->with(
            'root_level_timer->some_other_timer',
            ['default_tag' => 'default', 'type' => 'test']
        );

        \Magento\Framework\Profiler::add($driver);
        \Magento\Framework\Profiler::setDefaultTags(['default_tag' => 'default']);
        \Magento\Framework\Profiler::start('root_level_timer');
        \Magento\Framework\Profiler::start('some_other_timer', ['type' => 'test']);
    }

    public function testClearTimer()
    {
        $driver = $this->_getDriverMock();
        $driver->expects($this->at(0))->method('clear')->with('timer');

        \Magento\Framework\Profiler::add($driver);
        \Magento\Framework\Profiler::clear('timer');
    }

    /**
     */
    public function testClearException()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Timer name must not contain a nesting separator.');

        \Magento\Framework\Profiler::enable();
        \Magento\Framework\Profiler::clear('timer ' . \Magento\Framework\Profiler::NESTING_SEPARATOR . ' name');
    }

    public function testResetProfiler()
    {
        $this->markTestSkipped('Skipped in #27500 due to testing protected/private methods and properties');

        $driver = $this->_getDriverMock();
        $driver->expects($this->once())->method('clear')->with(null);

        \Magento\Framework\Profiler::add($driver);
        \Magento\Framework\Profiler::reset();

        //$this->assertAttributeEquals([], '_currentPath', \Magento\Framework\Profiler::class);
        //$this->assertAttributeEquals([], '_tagFilters', \Magento\Framework\Profiler::class);
        //$this->assertAttributeEquals([], '_defaultTags', \Magento\Framework\Profiler::class);
        //$this->assertAttributeEquals([], '_drivers', \Magento\Framework\Profiler::class);
        //$this->assertAttributeEquals(false, '_hasTagFilters', \Magento\Framework\Profiler::class);
        //$this->assertAttributeEquals(0, '_pathCount', \Magento\Framework\Profiler::class);
        //$this->assertAttributeEquals([], '_pathIndex', \Magento\Framework\Profiler::class);
    }

    /**
     * @param string $timerName
     * @param array $tags
     * @dataProvider skippedFilterDataProvider
     */
    public function testTagFilterSkip($timerName, array $tags = null)
    {
        $driver = $this->_getDriverMock();
        $driver->expects($this->never())->method('start');

        \Magento\Framework\Profiler::add($driver);
        \Magento\Framework\Profiler::addTagFilter('type', 'test');
        \Magento\Framework\Profiler::start($timerName, $tags);
    }

    /**
     * @return array
     */
    public function skippedFilterDataProvider()
    {
        return [
            'no tags' => ['timer', null],
            'no expected tags' => ['timer', ['tag' => 'value']],
            'no expected tag value' => ['timer', ['type' => 'db']]
        ];
    }

    /**
     * @param string $timerName
     * @param array $tags
     * @dataProvider passedFilterDataProvider
     */
    public function testTagFilterPass($timerName, array $tags = null)
    {
        $driver = $this->_getDriverMock();
        $driver->expects($this->once())->method('start')->with($timerName, $tags);

        \Magento\Framework\Profiler::add($driver);
        \Magento\Framework\Profiler::addTagFilter('type', 'test');
        \Magento\Framework\Profiler::start($timerName, $tags);
    }

    /**
     * @return array
     */
    public function passedFilterDataProvider()
    {
        return [
            'one expected tag' => ['timer', ['type' => 'test']],
            'more than one tag with expected' => ['timer', ['tag' => 'value', 'type' => 'test']]
        ];
    }

    public function testApplyConfig()
    {
        $this->markTestSkipped('Skipped in #27500 due to testing protected/private methods and properties');

        $mockDriver = $this->createMock(\Magento\Framework\Profiler\DriverInterface::class);
        $driverConfig = ['type' => 'foo'];
        $mockDriverFactory = $this->getMockBuilder(
            \Magento\Framework\Profiler\Driver\Factory::class
        )->disableOriginalConstructor()->getMock();
        $config = [
            'drivers' => [$driverConfig],
            'driverFactory' => $mockDriverFactory,
            'tagFilters' => ['tagName' => 'tagValue'],
        ];

        $mockDriverFactory->expects(
            $this->once()
        )->method(
            'create'
        )->with(
            $driverConfig
        )->willReturn(
            $mockDriver
        );

        \Magento\Framework\Profiler::applyConfig($config, '');
        //$this->assertAttributeEquals([$mockDriver], '_drivers', \Magento\Framework\Profiler::class);
        //$this->assertAttributeEquals(
        //    ['tagName' => ['tagValue']],
        //    '_tagFilters',
        //    \Magento\Framework\Profiler::class
        //);
        //$this->assertAttributeEquals(true, '_enabled', \Magento\Framework\Profiler::class);
    }

    /**
     * @dataProvider parseConfigDataProvider
     * @param array $data
     * @param boolean $isAjax
     * @param array $expected
     */
    public function testParseConfig($data, $isAjax, $expected)
    {
        $method = new \ReflectionMethod(\Magento\Framework\Profiler::class, '_parseConfig');
        $method->setAccessible(true);
        $this->assertEquals($expected, $method->invoke(null, $data, '', $isAjax));
    }

    /**
     * @return array
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function parseConfigDataProvider()
    {
        $driverFactory = new \Magento\Framework\Profiler\Driver\Factory();
        $otherDriverFactory = $this->createMock(\Magento\Framework\Profiler\Driver\Factory::class);
        return [
            'Empty configuration' => [
                [],
                false,
                [
                    'driverConfigs' => [],
                    'driverFactory' => $driverFactory,
                    'tagFilters' => [],
                    'baseDir' => null
                ],
            ],
            'Full configuration' => [
                [
                    'drivers' => [['type' => 'foo']],
                    'driverFactory' => $otherDriverFactory,
                    'tagFilters' => ['key' => 'value'],
                    'baseDir' => '/custom/base/dir',
                ],
                false,
                [
                    'driverConfigs' => [['type' => 'foo', 'baseDir' => '/custom/base/dir']],
                    'driverFactory' => $otherDriverFactory,
                    'tagFilters' => ['key' => 'value'],
                    'baseDir' => '/custom/base/dir'
                ],
            ],
            'Driver configuration with type in index' => [
                ['drivers' => ['foo' => 1]],
                false,
                [
                    'driverConfigs' => [['type' => 'foo']],
                    'driverFactory' => $driverFactory,
                    'tagFilters' => [],
                    'baseDir' => null
                ],
            ],
            'Driver configuration with type in value' => [
                ['drivers' => ['foo']],
                false,
                [
                    'driverConfigs' => [['type' => 'foo']],
                    'driverFactory' => $driverFactory,
                    'tagFilters' => [],
                    'baseDir' => null
                ],
            ],
            'Driver ignored configuration' => [
                ['drivers' => ['foo' => 0]],
                false,
                [
                    'driverConfigs' => [],
                    'driverFactory' => $driverFactory,
                    'tagFilters' => [],
                    'baseDir' => null
                ],
            ],
            'Non ajax call' => [
                1,
                false,
                [
                    'driverConfigs' => [['output' => 'html']],
                    'driverFactory' => $driverFactory,
                    'tagFilters' => [],
                    'baseDir' => ''
                ],
            ]
        ];
    }
}
