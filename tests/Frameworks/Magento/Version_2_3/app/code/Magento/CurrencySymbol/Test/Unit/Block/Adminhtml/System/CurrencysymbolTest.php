<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\CurrencySymbol\Test\Unit\Block\Adminhtml\System;

class CurrencysymbolTest extends \PHPUnit\Framework\TestCase
{
    /**
     * Object manager helper
     *
     * @var \Magento\Framework\TestFramework\Unit\Helper\ObjectManager
     */
    protected $objectManagerHelper;

    protected function setUp(): void
    {
        $this->objectManagerHelper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
    }

    protected function tearDown(): void
    {
        unset($this->objectManagerHelper);
    }

    public function testPrepareLayout()
    {
        $symbolSystemFactoryMock = $this->createPartialMock(
            \Magento\CurrencySymbol\Model\System\CurrencysymbolFactory::class,
            ['create']
        );

        $blockMock = $this->createPartialMock(
            \Magento\Framework\View\Element\BlockInterface::class,
            ['addChild', 'toHtml']
        );

        /** @var $layoutMock \Magento\Framework\View\LayoutInterface|\PHPUnit\Framework\MockObject\MockObject */
        $layoutMock = $this->getMockForAbstractClass(
            \Magento\Framework\View\LayoutInterface::class,
            [],
            '',
            false,
            false,
            true,
            ['getBlock']
        );

        $layoutMock->expects($this->once())->method('getBlock')->willReturn($blockMock);

        $blockMock->expects($this->once())
            ->method('addChild')
            ->with(
                'save_button',
                \Magento\Backend\Block\Widget\Button::class,
                [
                    'label' => __('Save Currency Symbols'),
                    'class' => 'save primary save-currency-symbols',
                    'data_attribute' => [
                        'mage-init' => ['button' => ['event' => 'save', 'target' => '#currency-symbols-form']],
                    ]
                ]
            );

        /** @var $block \Magento\CurrencySymbol\Block\Adminhtml\System\Currencysymbol */
        $block = $this->objectManagerHelper->getObject(
            \Magento\CurrencySymbol\Block\Adminhtml\System\Currencysymbol::class,
            [
                'symbolSystemFactory' => $symbolSystemFactoryMock,
                'layout' => $layoutMock
            ]
        );
        $block->setLayout($layoutMock);
    }
}
