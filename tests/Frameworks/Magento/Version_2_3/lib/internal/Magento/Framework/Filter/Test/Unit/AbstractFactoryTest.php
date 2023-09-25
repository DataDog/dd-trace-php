<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Filter\Test\Unit;

class AbstractFactoryTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\Filter\AbstractFactory
     */
    protected $_factory;

    /**
     * @var array
     */
    protected $_invokableList = [
        'sprintf' => \Magento\Framework\Filter\Sprintf::class,
        'template' => \Magento\Framework\Filter\Template::class,
        'arrayFilter' => \Magento\Framework\Filter\ArrayFilter::class,
    ];

    /**
     * @var array
     */
    protected $_sharedList = [
        \Magento\Framework\Filter\Template::class => true,
        \Magento\Framework\Filter\ArrayFilter::class => false,
    ];

    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    protected $_objectManager;

    protected function setUp(): void
    {
        $this->_objectManager = $this->createMock(\Magento\Framework\ObjectManagerInterface::class);

        $this->_factory = $this->getMockForAbstractClass(
            \Magento\Framework\Filter\AbstractFactory::class,
            ['objectManger' => $this->_objectManager]
        );
        $property = new \ReflectionProperty(\Magento\Framework\Filter\AbstractFactory::class, 'invokableClasses');
        $property->setAccessible(true);
        $property->setValue($this->_factory, $this->_invokableList);

        $property = new \ReflectionProperty(\Magento\Framework\Filter\AbstractFactory::class, 'shared');
        $property->setAccessible(true);
        $property->setValue($this->_factory, $this->_sharedList);
    }

    /**
     * @dataProvider canCreateFilterDataProvider
     * @param string $alias
     * @param bool $expectedResult
     */
    public function testCanCreateFilter($alias, $expectedResult)
    {
        $this->assertEquals($expectedResult, $this->_factory->canCreateFilter($alias));
    }

    /**
     * @return array
     */
    public function canCreateFilterDataProvider()
    {
        return [['arrayFilter', true], ['notExist', false]];
    }

    /**
     * @dataProvider isSharedDataProvider
     * @param string $alias
     * @param bool $expectedResult
     */
    public function testIsShared($alias, $expectedResult)
    {
        $this->assertEquals($expectedResult, $this->_factory->isShared($alias));
    }

    /**
     * @return array
     */
    public function isSharedDataProvider()
    {
        return [
            'shared' => [\Magento\Framework\Filter\Template::class, true],
            'not shared' => [\Magento\Framework\Filter\ArrayFilter::class, false],
            'default value' => [\Magento\Framework\Filter\Sprintf::class, true]
        ];
    }

    /**
     * @dataProvider createFilterDataProvider
     * @param string $alias
     * @param array $arguments
     * @param bool $isShared
     */
    public function testCreateFilter($alias, $arguments, $isShared)
    {
        $property = new \ReflectionProperty(\Magento\Framework\Filter\AbstractFactory::class, 'sharedInstances');
        $property->setAccessible(true);

        $filterMock = $this->getMockBuilder('FactoryInterface')->setMethods(['filter'])->getMock();
        $this->_objectManager->expects(
            $this->atLeastOnce()
        )->method(
            'create'
        )->with(
            $this->equalTo($this->_invokableList[$alias]),
            $this->equalTo($arguments)
        )->willReturn(
            $filterMock
        );

        $this->assertEquals($filterMock, $this->_factory->createFilter($alias, $arguments));
        if ($isShared) {
            $sharedList = $property->getValue($this->_factory);
            $this->assertArrayHasKey($alias, $sharedList);
            $this->assertEquals($filterMock, $sharedList[$alias]);
        } else {
            $this->assertEmpty($property->getValue($this->_factory));
        }
    }

    /**
     * @return array
     */
    public function createFilterDataProvider()
    {
        return [
            'not shared with args' => ['arrayFilter', ['123', '231'], false],
            'not shared without args' => ['arrayFilter', [], true],
            'shared' => ['template', [], true],
            'default shared' => ['sprintf', [], true]
        ];
    }
}
