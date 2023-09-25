<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Backend\Block\Widget;

/**
 * @magentoAppArea adminhtml
 */
class ContainerTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @magentoAppIsolation enabled
     */
    public function testPseudoConstruct()
    {
        /** @var $block \Magento\Backend\Block\Widget\Container */
        $block = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(
            \Magento\Framework\View\LayoutInterface::class
        )->createBlock(
            \Magento\Backend\Block\Widget\Container::class,
            '',
            [
                'data' => [
                    \Magento\Backend\Block\Widget\Container::PARAM_CONTROLLER => 'one',
                    \Magento\Backend\Block\Widget\Container::PARAM_HEADER_TEXT => 'two',
                ]
            ]
        );
        $this->assertStringEndsWith('one', $block->getHeaderCssClass());
        $this->assertStringContainsString('two', $block->getHeaderText());
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testGetButtonsHtml()
    {
        $titles = [1 => 'Title 1', 'Title 2', 'Title 3'];
        $block = $this->_buildBlock($titles);
        $html = $block->getButtonsHtml('header');

        $this->assertStringContainsString('<button', $html);
        foreach ($titles as $title) {
            $this->assertStringContainsString($title, $html);
        }
    }

    /**
     * @magentoAppIsolation enabled
     */
    public function testUpdateButton()
    {
        $originalTitles = [1 => 'Title 1', 'Title 2', 'Title 3'];
        $newTitles = [1 => 'Button A', 'Button B', 'Button C'];

        $block = $this->_buildBlock($originalTitles);
        foreach ($newTitles as $id => $newTitle) {
            $block->updateButton($id, 'title', $newTitle);
        }
        $html = $block->getButtonsHtml('header');
        foreach ($newTitles as $newTitle) {
            $this->assertStringContainsString($newTitle, $html);
        }
    }

    /**
     * Composes a container with several buttons in it
     *
     * @param array $titles
     * @param string $blockName
     * @return \Magento\Backend\Block\Widget\Container
     */
    protected function _buildBlock($titles, $blockName = 'block')
    {
        /** @var $layout \Magento\Framework\View\LayoutInterface */
        $layout = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(
            \Magento\Framework\View\LayoutInterface::class
        );
        /** @var $block \Magento\Backend\Block\Widget\Container */
        $block = $layout->createBlock(\Magento\Backend\Block\Widget\Container::class, $blockName);
        foreach ($titles as $id => $title) {
            $block->addButton($id, ['title' => $title], 0, 0, 'header');
        }
        $block->setLayout($layout);
        return $block;
    }
}
