<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Wishlist\Block\Customer\Wishlist;

class ItemsTest extends \PHPUnit\Framework\TestCase
{
    public function testGetColumns()
    {
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $layout = $objectManager->get(
            \Magento\Framework\View\LayoutInterface::class
        );
        $block = $layout->addBlock(\Magento\Wishlist\Block\Customer\Wishlist\Items::class, 'test');
        $child = $this->getMockBuilder(\Magento\Wishlist\Block\Customer\Wishlist\Item\Column::class)
            ->setMethods(['isEnabled'])
            ->disableOriginalConstructor()
            ->getMock();

        $child->expects($this->any())->method('isEnabled')->willReturn(true);
        $layout->addBlock($child, 'child', 'test');
        $expected = $child->getType();
        $columns = $block->getColumns();
        $this->assertNotEmpty($columns);
        foreach ($columns as $column) {
            $this->assertSame($expected, $column->getType());
        }
    }
}
