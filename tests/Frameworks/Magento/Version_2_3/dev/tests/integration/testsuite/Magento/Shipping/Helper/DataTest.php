<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Shipping\Helper;

use Magento\Store\Model\StoreManagerInterface;

class DataTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Shipping\Helper\Data
     */
    private $helper;

    protected function setUp(): void
    {
        $this->helper = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(
            \Magento\Shipping\Helper\Data::class
        );
    }

    /**
     * @param string $modelName
     * @param string $getIdMethod
     * @param int $entityId
     * @param string $code
     * @param string $expected
     * @dataProvider getTrackingPopupUrlBySalesModelDataProvider
     */
    public function testGetTrackingPopupUrlBySalesModel($modelName, $getIdMethod, $entityId, $code, $expected)
    {
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $constructArgs = [];
        if (\Magento\Sales\Model\Order\Shipment::class === $modelName) {
            $orderRepository = $this->getMockOrderRepository($code);
            $constructArgs['orderRepository'] = $orderRepository;
        } elseif (\Magento\Sales\Model\Order\Shipment\Track::class === $modelName) {
            $shipmentRepository = $this->getMockShipmentRepository($code);
            $constructArgs['shipmentRepository'] = $shipmentRepository;
        }

        $model = $objectManager->create($modelName, $constructArgs);
        $model->{$getIdMethod}($entityId);

        if (\Magento\Sales\Model\Order::class === $modelName) {
            $model->setProtectCode($code);
        }
        if (\Magento\Sales\Model\Order\Shipment\Track::class === $modelName) {
            $model->setParentId(1);
        }

        $actual = $this->helper->getTrackingPopupUrlBySalesModel($model);
        $this->assertEquals($expected, $actual);
    }

    /**
     * From the admin panel with custom URL we should have generated frontend URL
     *
     * @param string $modelName
     * @param string $getIdMethod
     * @param int $entityId
     * @param string $code
     * @param string $expected
     * @magentoAppArea adminhtml
     * @magentoConfigFixture admin_store web/unsecure/base_link_url http://admin.localhost/
     * @dataProvider getTrackingPopupUrlBySalesModelDataProvider
     */
    public function testGetTrackingPopupUrlBySalesModelFromAdmin($modelName, $getIdMethod, $entityId, $code, $expected)
    {
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();

        /** @var StoreManagerInterface $storeManager */
        $storeManager = $objectManager->create(StoreManagerInterface::class);
        $storeManager->reinitStores();

        $constructArgs = [];
        if (\Magento\Sales\Model\Order\Shipment::class === $modelName) {
            $orderRepository = $this->getMockOrderRepository($code);
            $constructArgs['orderRepository'] = $orderRepository;
        } elseif (\Magento\Sales\Model\Order\Shipment\Track::class === $modelName) {
            $shipmentRepository = $this->getMockShipmentRepository($code);
            $constructArgs['shipmentRepository'] = $shipmentRepository;
        }

        $model = $objectManager->create($modelName, $constructArgs);
        $model->{$getIdMethod}($entityId);

        if (\Magento\Sales\Model\Order::class === $modelName) {
            $model->setProtectCode($code);
        }
        if (\Magento\Sales\Model\Order\Shipment\Track::class === $modelName) {
            $model->setParentId(1);
        }

        //Frontend URL should be used there
        $actual = $this->helper->getTrackingPopupUrlBySalesModel($model);
        $this->assertEquals($expected, $actual);
    }

    /**
     * @param $code
     * @return \Magento\Sales\Api\OrderRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private function getMockOrderRepository($code)
    {
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $order = $objectManager->create(\Magento\Sales\Model\Order::class);
        $order->setProtectCode($code);
        $orderRepository = $this->createMock(\Magento\Sales\Api\OrderRepositoryInterface::class);
        $orderRepository->expects($this->atLeastOnce())->method('get')->willReturn($order);
        return $orderRepository;
    }

    /**
     * @param $code
     * @return \Magento\Sales\Model\Order\ShipmentRepository|\PHPUnit\Framework\MockObject\MockObject
     */
    private function getMockShipmentRepository($code)
    {
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $orderRepository = $this->getMockOrderRepository($code);
        $shipmentArgs = ['orderRepository' => $orderRepository];

        $shipment = $objectManager->create(\Magento\Sales\Model\Order\Shipment::class, $shipmentArgs);
        $shipmentRepository = $this->createPartialMock(\Magento\Sales\Model\Order\ShipmentRepository::class, ['get']);
        $shipmentRepository->expects($this->atLeastOnce())->method('get')->willReturn($shipment);
        return $shipmentRepository;
    }

    /**
     * @return array
     */
    public function getTrackingPopupUrlBySalesModelDataProvider()
    {
        return [
            [\Magento\Sales\Model\Order::class,
                'setId',
                42,
                'abc',
                'http://localhost/index.php/shipping/tracking/popup?hash=b3JkZXJfaWQ6NDI6YWJj',
            ],
            [\Magento\Sales\Model\Order\Shipment::class,
                'setId',
                42,
                'abc',
                'http://localhost/index.php/shipping/tracking/popup?hash=c2hpcF9pZDo0MjphYmM%2C'
            ],
            [\Magento\Sales\Model\Order\Shipment\Track::class,
                'setEntityId',
                42,
                'abc',
                'http://localhost/index.php/shipping/tracking/popup?hash=dHJhY2tfaWQ6NDI6YWJj'
            ]
        ];
    }
}
