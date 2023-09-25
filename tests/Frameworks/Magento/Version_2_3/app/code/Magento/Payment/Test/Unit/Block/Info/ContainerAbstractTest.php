<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Test class for \Magento\Payment\Block\Info\AbstractContainer
 */
namespace Magento\Payment\Test\Unit\Block\Info;

class ContainerAbstractTest extends \PHPUnit\Framework\TestCase
{
    public function testSetInfoTemplate()
    {
        $block = $this->createPartialMock(
            \Magento\Payment\Block\Info\AbstractContainer::class,
            ['getChildBlock', 'getPaymentInfo']
        );
        $objectManagerHelper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $paymentInfo = $objectManagerHelper->getObject(\Magento\Payment\Model\Info::class);
        $methodInstance = $objectManagerHelper->getObject(\Magento\OfflinePayments\Model\Checkmo::class);
        $paymentInfo->setMethodInstance($methodInstance);
        $block->expects($this->atLeastOnce())->method('getPaymentInfo')->willReturn($paymentInfo);

        $childBlock = $objectManagerHelper->getObject(\Magento\Framework\View\Element\Template::class);
        $block->expects(
            $this->atLeastOnce()
        )->method(
            'getChildBlock'
        )->with(
            'payment.info.checkmo'
        )->willReturn(
            $childBlock
        );

        $template = 'any_template.phtml';
        $this->assertNotEquals($template, $childBlock->getTemplate());
        $block->setInfoTemplate('checkmo', $template);
        $this->assertEquals($template, $childBlock->getTemplate());
    }
}
