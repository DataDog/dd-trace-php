<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

require __DIR__ . '/../../../Magento/Customer/_files/customer.php';
require __DIR__ . '/../../../Magento/Catalog/_files/product_simple.php';

$price = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(\Magento\ProductAlert\Model\Price::class);
$price->setCustomerId(
    $customer->getId()
)->setProductId(
    $product->getId()
)->setPrice(
    $product->getPrice()+1
)->setWebsiteId(
    1
);
$price->save();

$stock = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(\Magento\ProductAlert\Model\Stock::class);
$stock->setCustomerId(
    $customer->getId()
)->setProductId(
    $product->getId()
)->setWebsiteId(
    1
);
$stock->save();
