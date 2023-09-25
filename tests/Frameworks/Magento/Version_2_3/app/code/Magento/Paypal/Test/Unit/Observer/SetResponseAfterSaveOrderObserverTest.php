<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Paypal\Test\Unit\Observer;

/**
 * Class SetResponseAfterSaveOrderObserverTest
 */
class SetResponseAfterSaveOrderObserverTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Paypal\Observer\SetResponseAfterSaveOrderObserver
     */
    protected $_model;

    /**
     * @var \Magento\Framework\Event\Observer
     */
    protected $_observer;

    /**
     * @var \Magento\Framework\DataObject
     */
    protected $_event;

    /**
     * @var \Magento\Framework\Registry|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $coreRegistryMock;

    /**
     * @var \Magento\Paypal\Helper\Hss|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $paypalHssMock;

    /**
     * @var \Magento\Framework\App\ViewInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $viewMock;

    protected function setUp(): void
    {
        $this->_event = new \Magento\Framework\DataObject();

        $this->_observer = new \Magento\Framework\Event\Observer();
        $this->_observer->setEvent($this->_event);

        $this->coreRegistryMock = $this->createMock(\Magento\Framework\Registry::class);
        $this->paypalHssMock = $this->createPartialMock(\Magento\Paypal\Helper\Hss::class, ['getHssMethods']);
        $this->viewMock = $this->getMockForAbstractClass(
            \Magento\Framework\App\ViewInterface::class,
            [],
            '',
            false,
            true,
            true,
            []
        );
        $objectManagerHelper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->_model = $objectManagerHelper->getObject(
            \Magento\Paypal\Observer\SetResponseAfterSaveOrderObserver::class,
            [
                'coreRegistry' => $this->coreRegistryMock,
                'paypalHss' => $this->paypalHssMock,
                'view' => $this->viewMock,
            ]
        );
    }

    /**
     * Get data for test testSetResponseAfterSaveOrderSuccess
     *
     * @return array
     */
    protected function getSetResponseAfterSaveOrderTestData()
    {
        $iFrameHtml = 'iframe-html';
        $paymentMethod = 'method-2';

        return [
            'order.getId' => 10,
            'payment.getMethod' => $paymentMethod,
            'paypalHss.getHssMethods' => [
                'method-1',
                $paymentMethod,
                'method-3'
            ],
            'result.getData' => [
                'error' => false
            ],
            'block.toHtml' => $iFrameHtml,
            'result.setData' => [
                'error' => false,
                'update_section' => [
                    'name' => 'paypaliframe',
                    'html' => $iFrameHtml
                ],
                'redirect' => false,
                'success' => false,
            ]
        ];
    }

    /**
     * Run setResponseAfterSaveOrder method test
     *
     * @return void
     */
    public function testSetResponseAfterSaveOrderSuccess()
    {
        $testData = $this->getSetResponseAfterSaveOrderTestData();

        $observerMock = $this->getMockBuilder(\Magento\Framework\Event\Observer::class)
            ->disableOriginalConstructor()
            ->getMock();
        $orderMock = $this->getMockBuilder(\Magento\Sales\Model\Order::class)
            ->disableOriginalConstructor()
            ->getMock();
        $paymentMock = $this->getMockBuilder(\Magento\Sales\Model\Order\Payment::class)
            ->disableOriginalConstructor()
            ->getMock();
        $resultMock = $this->getMockBuilder(\Magento\Framework\DataObject::class)
            ->disableOriginalConstructor()
            ->getMock();
        $layoutMock = $this->getMockBuilder(\Magento\Framework\View\LayoutInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $blockMock = $this->getMockBuilder(\Magento\Framework\View\Element\BlockInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $this->coreRegistryMock->expects($this->once())
            ->method('registry')
            ->with('hss_order')
            ->willReturn($orderMock);

        $orderMock->expects($this->once())
            ->method('getId')
            ->willReturn($testData['order.getId']);
        $orderMock->expects($this->once())
            ->method('getPayment')
            ->willReturn($paymentMock);

        $paymentMock->expects($this->once())
            ->method('getMethod')
            ->willReturn($testData['payment.getMethod']);

        $this->paypalHssMock->expects($this->once())
            ->method('getHssMethods')
            ->willReturn($testData['paypalHss.getHssMethods']);

        $observerMock->expects($this->atLeastOnce())
            ->method('getData')
            ->with('result')
            ->willReturn($resultMock);

        $resultMock->expects($this->once())
            ->method('getData')
            ->willReturn($testData['result.getData']);

        $this->viewMock->expects($this->once())
            ->method('loadLayout')
            ->with('checkout_onepage_review', true, true, false);
        $this->viewMock->expects($this->once())
            ->method('getLayout')
            ->willReturn($layoutMock);

        $layoutMock->expects($this->once())
            ->method('getBlock')
            ->with('paypal.iframe')
            ->willReturn($blockMock);

        $blockMock->expects($this->once())
            ->method('toHtml')
            ->willReturn($testData['block.toHtml']);

        $resultMock->expects($this->once())
            ->method('setData')
            ->with($testData['result.setData']);

        $this->_model->execute($observerMock);
    }
}
