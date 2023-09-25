<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\View\Element;

class TextTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\View\Element\Text
     */
    protected $_block;

    protected function setUp(): void
    {
        $this->_block = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(
            \Magento\Framework\View\LayoutInterface::class
        )->createBlock(
            \Magento\Framework\View\Element\Text::class
        );
    }

    public function testSetGetText()
    {
        $this->_block->setText('text');
        $this->assertEquals('text', $this->_block->getText());
    }

    public function testAddText()
    {
        $this->_block->addText('a');
        $this->assertEquals('a', $this->_block->getText());

        $this->_block->addText('b');
        $this->assertEquals('ab', $this->_block->getText());

        $this->_block->addText('c', false);
        $this->assertEquals('abc', $this->_block->getText());

        $this->_block->addText('-', true);
        $this->assertEquals('-abc', $this->_block->getText());
    }

    public function testToHtml()
    {
        $this->_block->setText('test');
        $this->assertEquals('test', $this->_block->toHtml());
    }
}
