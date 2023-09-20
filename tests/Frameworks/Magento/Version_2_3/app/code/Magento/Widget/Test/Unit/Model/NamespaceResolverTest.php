<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Widget\Test\Unit\Model;

class NamespaceResolverTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Widget\Model\NamespaceResolver
     */
    protected $namespaceResolver;

    /**
     * @var \Magento\Framework\Module\ModuleListInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $moduleListMock;

    protected function setUp(): void
    {
        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->moduleListMock = $this->getMockBuilder(\Magento\Framework\Module\ModuleListInterface::class)
            ->getMockForAbstractClass();

        $this->namespaceResolver = $objectManager->getObject(
            \Magento\Widget\Model\NamespaceResolver::class,
            [
                'moduleList' => $this->moduleListMock
            ]
        );
    }

    /**
     * @param string $namespace
     * @param array $modules
     * @param string $expected
     * @param bool $asFullModuleName
     *
     * @dataProvider determineOmittedNamespaceDataProvider
     */
    public function testDetermineOmittedNamespace($namespace, $modules, $expected, $asFullModuleName)
    {
        $this->moduleListMock->expects($this->once())
            ->method('getNames')
            ->willReturn($modules);

        $this->assertSame(
            $expected,
            $this->namespaceResolver->determineOmittedNamespace($namespace, $asFullModuleName)
        );
    }

    /**
     * @return array
     */
    public function determineOmittedNamespaceDataProvider()
    {
        return[
            [
                'namespace' => \Magento\Widget\Test\Unit\Model\NamespaceResolverTest::class,
                'modules' => ['Magento_Cms', 'Magento_Catalog', 'Magento_Sales', 'Magento_Widget'],
                'expected' => 'Magento_Widget',
                'asFullModuleName' => true
            ],
            [
                'namespace' => \Magento\Widget\Test\Unit\Model\NamespaceResolverTest::class,
                'modules' => ['Magento_Cms', 'Magento_Catalog', 'Magento_Sales', 'Magento_Widget'],
                'expected' => 'magento_widget',
                'asFullModuleName' => false
            ],
            [
                'namespace' => 'Widget\Test\Unit\Model\NamespaceResolverTest',
                'modules' => ['Magento_Cms', 'Magento_Catalog', 'Magento_Sales', 'Magento_Widget'],
                'expected' => 'Magento_Widget',
                'asFullModuleName' => true

            ],
            [
                'namespace' => 'Widget\Test\Unit\Model\NamespaceResolverTest',
                'modules' => ['Magento_Cms', 'Magento_Catalog', 'Magento_Sales', 'Magento_Widget'],
                'expected' => 'widget',
                'asFullModuleName' => false
            ],
            [
                'namespace' => 'Unit\Model\NamespaceResolverTest',
                'modules' => ['Magento_Cms', 'Magento_Catalog', 'Magento_Sales', 'Magento_Widget'],
                'expected' => '',
                'asFullModuleName' => true
            ],
            [
                'namespace' => 'Unit\Model\NamespaceResolverTest',
                'modules' => ['Magento_Cms', 'Magento_Catalog', 'Magento_Sales', 'Magento_Widget'],
                'expected' => '',
                'asFullModuleName' => false
            ],
        ];
    }
}
