<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Shipping\Test\Unit\Block\Adminhtml\Order;

class TrackingTest extends \PHPUnit\Framework\TestCase
{
    public function testLookup()
    {
        $helper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);

        $shipment = new \Magento\Framework\DataObject(['store_id' => 1]);

        $registry = $this->createPartialMock(\Magento\Framework\Registry::class, ['registry']);
        $registry->expects(
            $this->once()
        )->method(
            'registry'
        )->with(
            'current_shipment'
        )->willReturn(
            $shipment
        );

        $carrier = $this->createPartialMock(
            \Magento\OfflineShipping\Model\Carrier\Freeshipping::class,
            ['isTrackingAvailable', 'getConfigData']
        );
        $carrier->expects($this->once())->method('isTrackingAvailable')->willReturn(true);
        $carrier->expects(
            $this->once()
        )->method(
            'getConfigData'
        )->with(
            'title'
        )->willReturn(
            'configdata'
        );

        $config = $this->createPartialMock(\Magento\Shipping\Model\Config::class, ['getAllCarriers']);
        $config->expects(
            $this->once()
        )->method(
            'getAllCarriers'
        )->with(
            1
        )->willReturn(
            ['free' => $carrier]
        );

        /** @var \Magento\Shipping\Block\Adminhtml\Order\Tracking $model */
        $model = $helper->getObject(
            \Magento\Shipping\Block\Adminhtml\Order\Tracking::class,
            ['registry' => $registry, 'shippingConfig' => $config]
        );

        $this->assertEquals(['custom' => 'Custom Value', 'free' => 'configdata'], $model->getCarriers());
    }
}
