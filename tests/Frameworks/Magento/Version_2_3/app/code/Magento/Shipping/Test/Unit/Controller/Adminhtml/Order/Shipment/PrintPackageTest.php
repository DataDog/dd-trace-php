<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Shipping\Test\Unit\Controller\Adminhtml\Order\Shipment;

use Magento\Framework\App\Filesystem\DirectoryList;

/**
 * Class PrintPackageTest
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class PrintPackageTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Shipping\Controller\Adminhtml\Order\ShipmentLoader|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $shipmentLoaderMock;

    /**
     * @var \Magento\Framework\App\Request\Http|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $requestMock;

    /**
     * @var \Magento\Framework\App\Response\Http|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $responseMock;

    /**
     * @var \Magento\Framework\App\Response\Http\FileFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $fileFactoryMock;

    /**
     * @var \Magento\Framework\ObjectManagerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $objectManagerMock;

    /**
     * @var \Magento\Backend\Model\Session|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $sessionMock;

    /**
     * @var \Magento\Framework\App\ActionFlag|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $actionFlag;

    /**
     * @var \Magento\Sales\Model\Order\Shipment|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $shipmentMock;

    /**
     * @var \Magento\Shipping\Controller\Adminhtml\Order\Shipment\PrintPackage
     */
    protected $controller;

    protected function setUp(): void
    {
        $orderId = 1;
        $shipmentId = 1;
        $shipment = [];
        $tracking = [];

        $this->shipmentLoaderMock = $this->createPartialMock(
            \Magento\Shipping\Controller\Adminhtml\Order\ShipmentLoader::class,
            ['setOrderId', 'setShipmentId', 'setShipment', 'setTracking', 'load']
        );
        $this->requestMock = $this->createPartialMock(\Magento\Framework\App\Request\Http::class, ['getParam']);
        $this->objectManagerMock = $this->createMock(\Magento\Framework\ObjectManagerInterface::class);
        $this->responseMock = $this->createMock(\Magento\Framework\App\Response\Http::class);
        $this->sessionMock = $this->createPartialMock(\Magento\Backend\Model\Session::class, ['setIsUrlNotice']);
        $this->actionFlag = $this->createPartialMock(\Magento\Framework\App\ActionFlag::class, ['get']);
        $this->shipmentMock = $this->createPartialMock(\Magento\Sales\Model\Order\Shipment::class, ['__wakeup']);
        $this->fileFactoryMock = $this->createPartialMock(
            \Magento\Framework\App\Response\Http\FileFactory::class,
            ['create']
        );

        $contextMock = $this->createPartialMock(
            \Magento\Backend\App\Action\Context::class,
            ['getRequest', 'getObjectManager', 'getResponse', 'getSession', 'getActionFlag']
        );

        $contextMock->expects($this->any())->method('getRequest')->willReturn($this->requestMock);
        $contextMock->expects($this->any())
            ->method('getObjectManager')
            ->willReturn($this->objectManagerMock);
        $contextMock->expects($this->any())->method('getResponse')->willReturn($this->responseMock);
        $contextMock->expects($this->any())->method('getSession')->willReturn($this->sessionMock);
        $contextMock->expects($this->any())->method('getActionFlag')->willReturn($this->actionFlag);

        $this->requestMock->expects($this->at(0))
            ->method('getParam')
            ->with('order_id')
            ->willReturn($orderId);
        $this->requestMock->expects($this->at(1))
            ->method('getParam')
            ->with('shipment_id')
            ->willReturn($shipmentId);
        $this->requestMock->expects($this->at(2))
            ->method('getParam')
            ->with('shipment')
            ->willReturn($shipment);
        $this->requestMock->expects($this->at(3))
            ->method('getParam')
            ->with('tracking')
            ->willReturn($tracking);
        $this->shipmentLoaderMock->expects($this->once())
            ->method('setOrderId')
            ->with($orderId);
        $this->shipmentLoaderMock->expects($this->once())
            ->method('setShipmentId')
            ->with($shipmentId);
        $this->shipmentLoaderMock->expects($this->once())
            ->method('setShipment')
            ->with($shipment);
        $this->shipmentLoaderMock->expects($this->once())
            ->method('setTracking')
            ->with($tracking);

        $this->controller = new \Magento\Shipping\Controller\Adminhtml\Order\Shipment\PrintPackage(
            $contextMock,
            $this->shipmentLoaderMock,
            $this->fileFactoryMock
        );
    }

    /**
     * Run test execute method
     */
    public function testExecute()
    {
        $date = '9999-99-99_77-77-77';
        $content = 'PDF content';

        $packagingMock = $this->createPartialMock(\Magento\Shipping\Model\Order\Pdf\Packaging::class, ['getPdf']);
        $pdfMock = $this->createPartialMock(\Zend_Pdf::class, ['render']);
        $dateTimeMock = $this->createPartialMock(\Magento\Framework\Stdlib\DateTime\DateTime::class, ['date']);

        $this->shipmentLoaderMock->expects($this->once())
            ->method('load')
            ->willReturn($this->shipmentMock);
        $this->objectManagerMock->expects($this->once())
            ->method('create')
            ->with(\Magento\Shipping\Model\Order\Pdf\Packaging::class)
            ->willReturn($packagingMock);
        $packagingMock->expects($this->once())
            ->method('getPdf')
            ->with($this->shipmentMock)
            ->willReturn($pdfMock);
        $this->objectManagerMock->expects($this->once())
            ->method('get')
            ->with(\Magento\Framework\Stdlib\DateTime\DateTime::class)
            ->willReturn($dateTimeMock);
        $dateTimeMock->expects($this->once())->method('date')->with('Y-m-d_H-i-s')->willReturn($date);
        $pdfMock->expects($this->once())->method('render')->willReturn($content);
        $this->fileFactoryMock->expects($this->once())
            ->method('create')
            ->with(
                'packingslip' . $date . '.pdf',
                $content,
                DirectoryList::VAR_DIR,
                'application/pdf'
            )->willReturn('result-pdf-content');

        $this->assertEquals('result-pdf-content', $this->controller->execute());
    }

    /**
     * Run test execute method (fail print)
     */
    public function testExecuteFail()
    {
        $this->shipmentLoaderMock->expects($this->once())
            ->method('load')
            ->willReturn(false);
        $this->shipmentLoaderMock->expects($this->once())
            ->method('load')
            ->willReturn(false);
        $this->actionFlag->expects($this->once())
            ->method('get')
            ->with('', \Magento\Backend\App\AbstractAction::FLAG_IS_URLS_CHECKED)
            ->willReturn(true);
        $this->sessionMock->expects($this->once())
            ->method('setIsUrlNotice')
            ->with(true);

        $this->assertNull($this->controller->execute());
    }
}
