<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

use Magento\Payment\Helper\Data;
use Magento\Sales\Api\ShipmentCommentRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment\Comment;
use Magento\Sales\Model\Order\ShipmentFactory;
use Magento\TestFramework\Helper\Bootstrap;

require 'default_rollback.php';
require __DIR__ . '/order.php';

/** @var Order $order */
$payment = $order->getPayment();
$paymentInfoBlock = Bootstrap::getObjectManager()->get(Data::class)
    ->getInfoBlock($payment);
$payment->setBlockMock($paymentInfoBlock);

$items = [];
foreach ($order->getItems() as $orderItem) {
    $items[$orderItem->getId()] = $orderItem->getQtyOrdered();
}
$shipment = Bootstrap::getObjectManager()->get(ShipmentFactory::class)->create($order, $items);

$shipment->setPackages([['1'], ['2']]);
$shipment->setShipmentStatus(\Magento\Sales\Model\Order\Shipment::STATUS_NEW);
$shipment->save();

$comments = [
    [
        'comment' => 'comment 1',
        'is_visible_on_front' => 1,
        'is_customer_notified' => 1,
    ],
    [
        'comment' => 'comment 2',
        'is_visible_on_front' => 1,
        'is_customer_notified' => 1,
    ],
    [
        'comment' => 'comment 3',
        'is_visible_on_front' => 1,
        'is_customer_notified' => 1,
    ],
    [
        'comment' => 'comment 4',
        'is_visible_on_front' => 1,
        'is_customer_notified' => 1,
    ],
    [
        'comment' => 'comment 5',
        'is_visible_on_front' => 0,
        'is_customer_notified' => 1,
    ],
];

/** @var ShipmentCommentRepositoryInterface $shipmentCommentRepository */
$shipmentCommentRepository = Bootstrap::getObjectManager()->get(ShipmentCommentRepositoryInterface::class);

foreach ($comments as $data) {
    /** @var $comment Comment */
    $comment = Bootstrap::getObjectManager()->create(Comment::class);
    $comment->setParentId($shipment->getId());
    $comment->setComment($data['comment']);
    $comment->setIsVisibleOnFront($data['is_visible_on_front']);
    $comment->setIsCustomerNotified($data['is_customer_notified']);
    $shipmentCommentRepository->save($comment);
}
