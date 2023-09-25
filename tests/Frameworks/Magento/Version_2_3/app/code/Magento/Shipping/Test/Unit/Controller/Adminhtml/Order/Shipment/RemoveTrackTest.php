<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Shipping\Test\Unit\Controller\Adminhtml\Order\Shipment;

/**
 * Class RemoveTrackTest
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class RemoveTrackTest extends \PHPUnit\Framework\TestCase
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
     * @var \Magento\Framework\ObjectManagerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $objectManagerMock;

    /**
     * @var \Magento\Sales\Model\Order\Shipment\Track|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $shipmentTrackMock;

    /**
     * @var \Magento\Sales\Model\Order\Shipment|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $shipmentMock;

    /**
     * @var \Magento\Framework\App\View|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $viewMock;

    /**
     * @var \Magento\Framework\App\Response\Http|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $responseMock;

    /**
     * @var \Magento\Framework\View\Result\Page|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $resultPageMock;

    /**
     * @var \Magento\Framework\View\Page\Config|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $pageConfigMock;

    /**
     * @var \Magento\Framework\View\Page\Title|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $pageTitleMock;

    /**
     * @var \Magento\Shipping\Controller\Adminhtml\Order\Shipment\RemoveTrack
     */
    protected $controller;

    protected function setUp(): void
    {
        $this->requestMock = $this->createPartialMock(\Magento\Framework\App\Request\Http::class, ['getParam']);
        $this->objectManagerMock = $this->createMock(\Magento\Framework\ObjectManagerInterface::class);
        $this->shipmentTrackMock = $this->createPartialMock(
            \Magento\Sales\Model\Order\Shipment\Track::class,
            ['load', 'getId', 'delete', '__wakeup']
        );
        $this->shipmentMock = $this->createPartialMock(
            \Magento\Sales\Model\Order\Shipment::class,
            ['getIncrementId', '__wakeup']
        );
        $this->viewMock = $this->createPartialMock(
            \Magento\Framework\App\View::class,
            ['loadLayout', 'getLayout', 'getPage']
        );
        $this->responseMock = $this->createMock(\Magento\Framework\App\Response\Http::class);
        $this->shipmentLoaderMock = $this->createPartialMock(
            \Magento\Shipping\Controller\Adminhtml\Order\ShipmentLoader::class,
            ['setOrderId', 'setShipmentId', 'setShipment', 'setTracking', 'load']
        );
        $this->resultPageMock = $this->getMockBuilder(\Magento\Framework\View\Result\Page::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->pageConfigMock = $this->getMockBuilder(\Magento\Framework\View\Page\Config::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->pageTitleMock = $this->getMockBuilder(\Magento\Framework\View\Page\Title::class)
            ->disableOriginalConstructor()
            ->getMock();

        $contextMock = $this->createPartialMock(
            \Magento\Backend\App\Action\Context::class,
            ['getRequest', 'getObjectManager', 'getTitle', 'getView', 'getResponse']
        );

        $this->objectManagerMock->expects($this->once())
            ->method('create')
            ->with(\Magento\Sales\Model\Order\Shipment\Track::class)
            ->willReturn($this->shipmentTrackMock);

        $contextMock->expects($this->any())->method('getRequest')->willReturn($this->requestMock);
        $contextMock->expects($this->any())
            ->method('getObjectManager')
            ->willReturn($this->objectManagerMock);
        $contextMock->expects($this->any())->method('getView')->willReturn($this->viewMock);
        $contextMock->expects($this->any())->method('getResponse')->willReturn($this->responseMock);

        $this->controller = new \Magento\Shipping\Controller\Adminhtml\Order\Shipment\RemoveTrack(
            $contextMock,
            $this->shipmentLoaderMock
        );

        $this->viewMock->expects($this->any())
            ->method('getPage')
            ->willReturn($this->resultPageMock);
        $this->resultPageMock->expects($this->any())
            ->method('getConfig')
            ->willReturn($this->pageConfigMock);
        $this->pageConfigMock->expects($this->any())
            ->method('getTitle')
            ->willReturn($this->pageTitleMock);
    }

    /**
     * Shipment load sections
     *
     * @return void
     */
    protected function shipmentLoad()
    {
        $orderId = 1;
        $shipmentId = 1;
        $trackId = 1;
        $shipment = [];
        $tracking = [];

        $this->shipmentTrackMock->expects($this->once())
            ->method('load')
            ->with($trackId)
            ->willReturnSelf();
        $this->shipmentTrackMock->expects($this->once())
            ->method('getId')
            ->willReturn($trackId);
        $this->requestMock->expects($this->at(0))
            ->method('getParam')
            ->with('track_id')
            ->willReturn($trackId);
        $this->requestMock->expects($this->at(1))
            ->method('getParam')
            ->with('order_id')
            ->willReturn($orderId);
        $this->requestMock->expects($this->at(2))
            ->method('getParam')
            ->with('shipment_id')
            ->willReturn($shipmentId);
        $this->requestMock->expects($this->at(3))
            ->method('getParam')
            ->with('shipment')
            ->willReturn($shipment);
        $this->requestMock->expects($this->at(4))
            ->method('getParam')
            ->with('tracking')
            ->willReturn($tracking);
        $this->shipmentLoaderMock->expects($this->once())->method('setOrderId')->with($orderId);
        $this->shipmentLoaderMock->expects($this->once())->method('setShipmentId')->with($shipmentId);
        $this->shipmentLoaderMock->expects($this->once())->method('setShipment')->with($shipment);
        $this->shipmentLoaderMock->expects($this->once())->method('setTracking')->with($tracking);
    }

    /**
     * Represent json json section
     *
     * @param array $errors
     * @return void
     */
    protected function representJson(array $errors)
    {
        $jsonHelper = $this->createPartialMock(\Magento\Framework\Json\Helper\Data::class, ['jsonEncode']);
        $jsonHelper->expects($this->once())
            ->method('jsonEncode')
            ->with($errors)
            ->willReturn('{json}');
        $this->objectManagerMock->expects($this->once())
            ->method('get')
            ->with(\Magento\Framework\Json\Helper\Data::class)
            ->willReturn($jsonHelper);
        $this->responseMock->expects($this->once())
            ->method('representJson')
            ->with('{json}');
    }

    /**
     * Run test execute method
     */
    public function testExecute()
    {
        $response = 'html-data';
        $this->shipmentLoad();

        $this->shipmentLoaderMock->expects($this->once())
            ->method('load')
            ->willReturn($this->shipmentMock);
        $this->shipmentTrackMock->expects($this->once())
            ->method('delete')
            ->willReturnSelf();

        $layoutMock = $this->createPartialMock(\Magento\Framework\View\Layout::class, ['getBlock']);
        $trackingBlockMock = $this->createPartialMock(
            \Magento\Shipping\Block\Adminhtml\Order\Tracking::class,
            ['toHtml']
        );

        $trackingBlockMock->expects($this->once())
            ->method('toHtml')
            ->willReturn($response);
        $layoutMock->expects($this->once())
            ->method('getBlock')
            ->with('shipment_tracking')
            ->willReturn($trackingBlockMock);
        $this->viewMock->expects($this->once())->method('loadLayout')->willReturnSelf();
        $this->viewMock->expects($this->any())->method('getLayout')->willReturn($layoutMock);
        $this->responseMock->expects($this->once())
            ->method('setBody')
            ->with($response);

        $this->assertNull($this->controller->execute());
    }

    /**
     * Run test execute method (fail track load)
     */
    public function testExecuteTrackIdFail()
    {
        $trackId = null;
        $errors = ['error' => true, 'message' => 'We can\'t load track with retrieving identifier right now.'];

        $this->shipmentTrackMock->expects($this->once())
            ->method('load')
            ->with($trackId)
            ->willReturnSelf();
        $this->shipmentTrackMock->expects($this->once())
            ->method('getId')
            ->willReturn($trackId);
        $this->representJson($errors);

        $this->assertNull($this->controller->execute());
    }

    /**
     * Run test execute method (fail load shipment)
     */
    public function testExecuteShipmentLoadFail()
    {
        $errors = [
            'error' => true,
            'message' => 'We can\'t initialize shipment for delete tracking number.',
        ];
        $this->shipmentLoad();

        $this->shipmentLoaderMock->expects($this->once())
            ->method('load')
            ->willReturn(null);
        $this->representJson($errors);

        $this->assertNull($this->controller->execute());
    }

    /**
     * Run test execute method (delete exception)
     */
    public function testExecuteDeleteFail()
    {
        $errors = ['error' => true, 'message' => 'We can\'t delete tracking number.'];
        $this->shipmentLoad();

        $this->shipmentLoaderMock->expects($this->once())
            ->method('load')
            ->willReturn($this->shipmentMock);
        $this->shipmentTrackMock->expects($this->once())
            ->method('delete')
            ->will($this->throwException(new \Exception()));
        $this->representJson($errors);

        $this->assertNull($this->controller->execute());
    }
}
