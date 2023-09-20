<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Backend\Block\Widget\Grid;

/**
 * @magentoAppArea adminhtml
 */
class ColumnSetTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Backend\Block\Widget\Grid\ColumnSet
     */
    protected $_block;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $_layoutMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $_columnMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->_columnMock = $this->createPartialMock(
            \Magento\Backend\Block\Widget\Grid\Column::class,
            ['setSortable', 'setRendererType', 'setFilterType', 'addHeaderCssClass', 'setGrid']
        );
        $this->_layoutMock = $this->createMock(\Magento\Framework\View\Layout::class);
        $this->_layoutMock->expects(
            $this->any()
        )->method(
            'getChildBlocks'
        )->willReturn(
            [$this->_columnMock]
        );

        $context = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
            \Magento\Framework\View\Element\Template\Context::class,
            ['layout' => $this->_layoutMock]
        );
        $this->_block = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(
            \Magento\Framework\View\LayoutInterface::class
        )->createBlock(
            \Magento\Backend\Block\Widget\Grid\ColumnSet::class,
            '',
            ['context' => $context]
        );
        $this->_block->setTemplate(null);
    }

    public function testBeforeToHtmlAddsClassToLastColumn()
    {
        $this->_columnMock->expects($this->any())->method('addHeaderCssClass')->with($this->equalTo('last'));
        $this->_block->toHtml();
    }
}
