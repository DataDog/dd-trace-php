<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

$objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();

/** @var \Magento\Sales\Model\Order\Payment $paymentInfo */
$paymentInfo = $objectManager->create(\Magento\Sales\Model\Order\Payment::class);
$paymentInfo->setMethod('Cc')->setData('cc_number_enc', '1111111111');

/** @var \Magento\Sales\Model\Order $order */
$order = $objectManager->create(\Magento\Sales\Model\Order::class);
$order->setIncrementId(
    '100000001'
)->setPayment(
    $paymentInfo
);
$order->save();
