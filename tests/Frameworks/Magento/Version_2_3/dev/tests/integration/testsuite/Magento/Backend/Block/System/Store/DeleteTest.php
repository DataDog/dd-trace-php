<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Backend\Block\System\Store;

/**
 * @magentoAppArea adminhtml
 */
class DeleteTest extends \PHPUnit\Framework\TestCase
{
    public function testGetHeaderText()
    {
        /** @var $layout \Magento\Framework\View\Layout */
        $layout = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(
            \Magento\Framework\View\LayoutInterface::class
        );
        /** @var $block \Magento\Backend\Block\System\Store\Delete */
        $block = $layout->createBlock(\Magento\Backend\Block\System\Store\Delete::class, 'block');

        $dataObject = new \Magento\Framework\DataObject();
        $form = $block->getChildBlock('form');
        $form->setDataObject($dataObject);

        $expectedValue = 'header_text_test';
        $this->assertStringNotContainsString($expectedValue, (string)$block->getHeaderText());

        $dataObject->setName($expectedValue);
        $this->assertStringContainsString($expectedValue, (string)$block->getHeaderText());
    }
}
