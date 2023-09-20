<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\CurrencySymbol\Test\Unit\Block\Adminhtml\System\Currency\Rate;

class ServicesTest extends \PHPUnit\Framework\TestCase
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
        $options = [['value' => 'value', 'label' => 'label']];
        $service = 'service';

        $sourceServiceFactoryMock = $this->createPartialMock(
            \Magento\Directory\Model\Currency\Import\Source\ServiceFactory::class,
            ['create']
        );
        $sourceServiceMock = $this->createMock(\Magento\Directory\Model\Currency\Import\Source\Service::class);
        $backendSessionMock = $this->createPartialMock(
            \Magento\Backend\Model\Session::class,
            ['getCurrencyRateService']
        );

        /** @var $layoutMock \Magento\Framework\View\LayoutInterface|\PHPUnit\Framework\MockObject\MockObject */
        $layoutMock = $this->getMockForAbstractClass(
            \Magento\Framework\View\LayoutInterface::class,
            [],
            '',
            false,
            false,
            true,
            ['createBlock']
        );

        $blockMock = $this->createPartialMock(
            \Magento\Framework\View\Element\Html\Select::class,
            ['setOptions', 'setId', 'setName', 'setValue', 'setTitle']
        );

        $layoutMock->expects($this->once())->method('createBlock')->willReturn($blockMock);

        $sourceServiceFactoryMock->expects($this->once())->method('create')->willReturn($sourceServiceMock);
        $sourceServiceMock->expects($this->once())->method('toOptionArray')->willReturn($options);
        $backendSessionMock->expects($this->once())->method('getCurrencyRateService')->with(true)->willReturn($service);

        $blockMock->expects($this->once())->method('setOptions')->with($options)->willReturnSelf();
        $blockMock->expects($this->once())->method('setId')->with('rate_services')->willReturnSelf();
        $blockMock->expects($this->once())->method('setName')->with('rate_services')->willReturnSelf();
        $blockMock->expects($this->once())->method('setValue')->with($service)->willReturnSelf();
        $blockMock->expects($this->once())->method('setTitle')->with('Import Service')->willReturnSelf();

        /** @var $block \Magento\CurrencySymbol\Block\Adminhtml\System\Currency\Rate\Services */
        $block = $this->objectManagerHelper->getObject(
            \Magento\CurrencySymbol\Block\Adminhtml\System\Currency\Rate\Services::class,
            [
                'srcCurrencyFactory' => $sourceServiceFactoryMock,
                'backendSession' => $backendSessionMock
            ]
        );
        $block->setLayout($layoutMock);
    }
}
