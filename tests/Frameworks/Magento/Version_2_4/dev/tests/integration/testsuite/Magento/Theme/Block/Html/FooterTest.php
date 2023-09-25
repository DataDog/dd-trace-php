<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Theme\Block\Html;

use Magento\Customer\Model\Context;

class FooterTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Theme\Model\Theme
     */
    protected $_theme;

    protected function setUp(): void
    {
        \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(\Magento\Framework\App\State::class)
            ->setAreaCode('frontend');
        $design = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(
            \Magento\Framework\View\DesignInterface::class
        );
        $this->_theme = $design->setDefaultDesignTheme()->getDesignTheme();
    }

    public function testGetCacheKeyInfo()
    {
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $context = $objectManager->get(\Magento\Framework\App\Http\Context::class);
        $context->setValue(Context::CONTEXT_AUTH, false, false);
        $block = $objectManager->get(\Magento\Framework\View\LayoutInterface::class)
            ->createBlock(\Magento\Theme\Block\Html\Footer::class)
            ->setTemplate('html/copyright.phtml');
        $storeId = $objectManager->get(\Magento\Store\Model\StoreManagerInterface::class)->getStore()->getId();
        $expected = [
            'PAGE_FOOTER',
            $storeId,
            0,
            $this->_theme->getId(),
            false,
            $block->getTemplateFile(),
            'template' => 'html/copyright.phtml'
        ];
        $this->assertEquals(
            $expected,
            $block->getCacheKeyInfo()
        );
    }
}
