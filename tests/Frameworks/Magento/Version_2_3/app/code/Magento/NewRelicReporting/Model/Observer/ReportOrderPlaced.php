<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\NewRelicReporting\Model\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\NewRelicReporting\Model\Config;

/**
 * Class ReportOrderPlaced
 */
class ReportOrderPlaced implements ObserverInterface
{
    /**
     * @var Config
     */
    protected $config;

    /**
     * @var \Magento\NewRelicReporting\Model\OrdersFactory
     */
    protected $ordersFactory;

    /**
     * @param Config $config
     * @param \Magento\NewRelicReporting\Model\OrdersFactory $ordersFactory
     */
    public function __construct(
        Config $config,
        \Magento\NewRelicReporting\Model\OrdersFactory $ordersFactory
    ) {
        $this->config = $config;
        $this->ordersFactory = $ordersFactory;
    }

    /**
     * Reports orders placed to the database reporting_orders table
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        if ($this->config->isNewRelicEnabled()) {
            /** @var \Magento\Sales\Model\Order $order */
            $order = $observer->getEvent()->getOrder();
            $itemCount = $order->getTotalItemCount();
            if (!is_numeric($itemCount) && empty($itemCount)) {
                $itemCount = $order->getTotalQtyOrdered();
            }

            $modelData = [
                'customer_id' => $order->getCustomerId(),
                'total' => $order->getGrandTotal(),
                'total_base' => $order->getBaseGrandTotal(),
                'item_count' => $itemCount,
            ];

            /** @var \Magento\NewRelicReporting\Model\Orders $orderModel */
            $orderModel = $this->ordersFactory->create();
            $orderModel->setData($modelData);
            $orderModel->save();
        }
    }
}
