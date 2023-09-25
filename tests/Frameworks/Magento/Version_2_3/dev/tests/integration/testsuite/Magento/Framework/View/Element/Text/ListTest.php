<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\View\Element\Text;

class ListTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\View\LayoutInterface
     */
    protected $_layout;

    /**
     * @var \Magento\Framework\View\Element\Text\ListText
     */
    protected $_block;

    protected function setUp(): void
    {
        $this->_layout = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(
            \Magento\Framework\View\LayoutInterface::class
        );
        $this->_block = $this->_layout->createBlock(\Magento\Framework\View\Element\Text\ListText::class);
    }

    public function testToHtml()
    {
        $children = [
            ['block1', \Magento\Framework\View\Element\Text::class, 'text1'],
            ['block2', \Magento\Framework\View\Element\Text::class, 'text2'],
            ['block3', \Magento\Framework\View\Element\Text::class, 'text3'],
        ];
        foreach ($children as $child) {
            $this->_layout->addBlock($child[1], $child[0], $this->_block->getNameInLayout())->setText($child[2]);
        }
        $html = $this->_block->toHtml();
        $this->assertEquals('text1text2text3', $html);
    }

    public function testToHtmlWithContainer()
    {
        $listName = $this->_block->getNameInLayout();
        $block1 = $this->_layout->addBlock(\Magento\Framework\View\Element\Text::class, '', $listName);
        $this->_layout->addContainer('container', 'Container', [], $listName);
        $block2 = $this->_layout->addBlock(\Magento\Framework\View\Element\Text::class, '', 'container');
        $block3 = $this->_layout->addBlock(\Magento\Framework\View\Element\Text::class, '', $listName);
        $block1->setText('text1');
        $block2->setText('text2');
        $block3->setText('text3');
        $html = $this->_block->toHtml();
        $this->assertEquals('text1text2text3', $html);
    }
}
