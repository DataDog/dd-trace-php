<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\View\Test\Unit\PageLayout;

/**
 * Page layouts configuration
 */
class ConfigTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\View\PageLayout\Config
     */
    protected $config;

    protected function setUp(): void
    {
        $urnResolver = new \Magento\Framework\Config\Dom\UrnResolver();
        $urnResolverMock = $this->createMock(\Magento\Framework\Config\Dom\UrnResolver::class);
        $urnResolverMock->expects($this->once())
            ->method('getRealPath')
            ->with('urn:magento:framework:View/PageLayout/etc/layouts.xsd')
            ->willReturn($urnResolver->getRealPath('urn:magento:framework:View/PageLayout/etc/layouts.xsd'));
        $validationStateMock = $this->createMock(\Magento\Framework\Config\ValidationStateInterface::class);
        $validationStateMock->method('isValidationRequired')
            ->willReturn(true);
        $domFactoryMock = $this->createMock(\Magento\Framework\Config\DomFactory::class);
        $domFactoryMock->expects($this->once())
            ->method('createDom')
            ->willReturnCallback(
                function ($arguments) use ($validationStateMock) {
                    // @codingStandardsIgnoreStart
                    return new \Magento\Framework\Config\Dom(
                        '<?xml version="1.0" encoding="UTF-8"?>'
                            . '<page_layouts xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"></page_layouts>',
                        $validationStateMock,
                        ['/page_layouts/layout' => 'id'],
                        null,
                        $arguments['schemaFile']
                    );
                    // @codingStandardsIgnoreEnd
                }
            );
        $objectManagerHelper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->config = $objectManagerHelper->getObject(
            \Magento\Framework\View\PageLayout\Config::class,
            [
                'urnResolver' => $urnResolverMock,
                'configFiles' => [
                    'layouts_one.xml' => file_get_contents(__DIR__ . '/_files/layouts_one.xml'),
                    'layouts_two.xml' => file_get_contents(__DIR__ . '/_files/layouts_two.xml'),
                ],
                'domFactory' => $domFactoryMock
            ]
        );
    }

    public function testGetPageLayouts()
    {
        $this->assertEquals(['one' => 'One', 'two' => 'Two'], $this->config->getPageLayouts());
    }

    public function testHasPageLayout()
    {
        $this->assertTrue($this->config->hasPageLayout('one'));
        $this->assertFalse($this->config->hasPageLayout('three'));
    }

    public function testGetOptions()
    {
        $this->assertEquals(['one' => 'One', 'two' => 'Two'], $this->config->getPageLayouts());
    }

    public function testToOptionArray()
    {
        $this->assertEquals(
            [
                ['label' => 'One', 'value' => 'one'],
                ['label' => 'Two', 'value' => 'two'],
            ],
            $this->config->toOptionArray()
        );
        $this->assertEquals(
            [
                ['label' => '-- Please Select --', 'value' => ''],
                ['label' => 'One', 'value' => 'one'],
                ['label' => 'Two', 'value' => 'two'],
            ],
            $this->config->toOptionArray(true)
        );
    }
}
