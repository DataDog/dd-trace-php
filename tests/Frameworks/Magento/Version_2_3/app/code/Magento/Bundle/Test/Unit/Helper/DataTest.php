<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Bundle\Test\Unit\Helper;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;

class DataTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Catalog\Model\ProductTypes\ConfigInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $config;

    /**
     * @var \Magento\Bundle\Helper\Data
     */
    protected $helper;

    protected function setUp(): void
    {
        $this->config = $this->createMock(\Magento\Catalog\Model\ProductTypes\ConfigInterface::class);
        $this->helper = (new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this))->getObject(
            \Magento\Bundle\Helper\Data::class,
            ['config' => $this->config]
        );
    }

    public function testGetAllowedSelectionTypes()
    {
        $configData = ['allowed_selection_types' => ['foo', 'bar', 'baz']];
        $this->config->expects($this->once())->method('getType')->with('bundle')->willReturn($configData);

        $this->assertEquals($configData['allowed_selection_types'], $this->helper->getAllowedSelectionTypes());
    }

    public function testGetAllowedSelectionTypesIfTypesIsNotSet()
    {
        $configData = [];
        $this->config->expects($this->once())->method('getType')
            ->with(\Magento\Catalog\Model\Product\Type::TYPE_BUNDLE)
            ->willReturn($configData);

        $this->assertEquals([], $this->helper->getAllowedSelectionTypes());
    }
}
