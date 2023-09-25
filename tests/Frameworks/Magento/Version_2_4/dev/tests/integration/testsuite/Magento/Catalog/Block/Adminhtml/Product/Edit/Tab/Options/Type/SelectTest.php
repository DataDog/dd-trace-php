<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Block\Adminhtml\Product\Edit\Tab\Options\Type;

/**
 * @magentoAppArea adminhtml
 */
class SelectTest extends \PHPUnit\Framework\TestCase
{
    public function testToHtmlFormId()
    {
        /** @var $layout \Magento\Framework\View\Layout */
        $layout = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(
            \Magento\Framework\View\LayoutInterface::class
        );
        /** @var $block \Magento\Catalog\Block\Adminhtml\Product\Edit\Tab\Options\Type\Select */
        $block = $layout->createBlock(
            \Magento\Catalog\Block\Adminhtml\Product\Edit\Tab\Options\Type\Select::class,
            'select'
        );
        $html = $block->getPriceTypeSelectHtml();
        $this->assertStringContainsString('select_<%- data.select_id %>', $html);
        $this->assertStringContainsString('[<%- data.select_id %>]', $html);
    }
}
