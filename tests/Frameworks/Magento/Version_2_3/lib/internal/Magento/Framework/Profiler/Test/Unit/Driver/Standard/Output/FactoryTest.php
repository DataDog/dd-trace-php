<?php
/**
 * Test class for \Magento\Framework\Profiler\Driver\Standard\Output\Factory
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Profiler\Test\Unit\Driver\Standard\Output;

class FactoryTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\Profiler\Driver\Standard\Output\Factory
     */
    protected $_factory;

    /**
     * @var string
     */
    protected $_defaultOutputPrefix = 'Magento_Framework_Profiler_Driver_Standard_Output_Test_';

    /**
     * @var string
     */
    protected $_defaultOutputType = 'default';

    protected function setUp(): void
    {
        $this->_factory = new \Magento\Framework\Profiler\Driver\Standard\Output\Factory(
            $this->_defaultOutputPrefix,
            $this->_defaultOutputType
        );
    }

    public function testConstructor()
    {
        $this->markTestSkipped('Skipped in #27500 due to testing protected/private methods and properties');

        //$this->assertAttributeEquals($this->_defaultOutputPrefix, '_defaultOutputPrefix', $this->_factory);
        //$this->assertAttributeEquals($this->_defaultOutputType, '_defaultOutputType', $this->_factory);
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     */
    public function testDefaultConstructor()
    {
        $this->markTestSkipped('Skipped in #27500 due to testing protected/private methods and properties');

        $factory = new \Magento\Framework\Profiler\Driver\Standard\Output\Factory();
        //$this->assertAttributeNotEmpty('_defaultOutputPrefix', $factory);
        //$this->assertAttributeNotEmpty('_defaultOutputType', $factory);
    }

    /**
     * @dataProvider createDataProvider
     * @param array $configData
     * @param string $expectedClass
     */
    public function testCreate($configData, $expectedClass)
    {
        $driver = $this->_factory->create($configData);
        $this->assertInstanceOf($expectedClass, $driver);
        $this->assertInstanceOf(\Magento\Framework\Profiler\Driver\Standard\OutputInterface::class, $driver);
    }

    /**
     * @return array
     */
    public function createDataProvider()
    {
        $defaultOutputClass = $this->getMockClass(
            \Magento\Framework\Profiler\Driver\Standard\OutputInterface::class,
            [],
            [],
            'Magento_Framework_Profiler_Driver_Standard_Output_Test_Default'
        );
        $testOutputClass = $this->getMockClass(
            \Magento\Framework\Profiler\Driver\Standard\OutputInterface::class,
            [],
            [],
            'Magento_Framework_Profiler_Driver_Standard_Output_Test_Test'
        );
        return [
            'Prefix and concrete type' => [['type' => 'test'], $testOutputClass],
            'Prefix and default type' => [[], $defaultOutputClass],
            'Concrete class' => [['type' => $testOutputClass], $testOutputClass]
        ];
    }

    public function testCreateUndefinedClass()
    {
        $this->expectException('InvalidArgumentException');
        $this->expectExceptionMessage(
            sprintf(
                'Cannot create standard driver output, class "%s" doesn\'t exist.',
                'Magento_Framework_Profiler_Driver_Standard_Output_Test_Baz'
            )
        );
        $this->_factory->create(['type' => 'baz']);
    }

    public function testCreateInvalidClass()
    {
        $this->expectException('InvalidArgumentException');
        $this->expectExceptionMessage(
            'Output class "stdClass" must implement \Magento\Framework\Profiler\Driver\Standard\OutputInterface.'
        );
        $this->_factory->create(['type' => 'stdClass']);
    }
}
