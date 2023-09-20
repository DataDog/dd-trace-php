<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\GoogleOptimizer\Test\Unit\Block\Code;

class CategoryTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\GoogleOptimizer\Block\Code\Category
     */
    protected $block;

    /**
     * @var \Magento\Framework\Registry|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $registry;

    protected function setUp(): void
    {
        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->registry = $this->createMock(\Magento\Framework\Registry::class);
        $this->block = $objectManager->getObject(
            \Magento\GoogleOptimizer\Block\Code\Category::class,
            ['registry' => $this->registry]
        );
    }

    protected function tearDown(): void
    {
        $this->block = null;
    }

    public function testGetIdentities()
    {
        $categoryTags = ['catalog_category_1'];
        $category = $this->createMock(\Magento\Catalog\Model\Category::class);
        $category->expects($this->once())->method('getIdentities')->willReturn($categoryTags);
        $this->registry->expects(
            $this->once()
        )->method(
            'registry'
        )->with(
            'current_category'
        )->willReturn(
            $category
        );
        $this->assertEquals($categoryTags, $this->block->getIdentities());
    }
}
