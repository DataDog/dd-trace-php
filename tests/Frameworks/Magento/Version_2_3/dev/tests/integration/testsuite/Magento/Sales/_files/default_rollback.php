<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

use Magento\TestFramework\Helper\Bootstrap;

/** @var \Magento\Framework\Registry $registry */
$registry = Bootstrap::getObjectManager()->get(\Magento\Framework\Registry::class);
$registry->unregister('isSecureArea');
$registry->register('isSecureArea', true);

/** @var $order \Magento\Sales\Model\Order */
$orderCollection = Bootstrap::getObjectManager()->create(\Magento\Sales\Model\ResourceModel\Order\Collection::class);
foreach ($orderCollection as $order) {
    $order->delete();
}

/** @var $product \Magento\Catalog\Model\Product */
$productCollection = Bootstrap::getObjectManager()->create(
    \Magento\Catalog\Model\ResourceModel\Product\Collection::class
);
foreach ($productCollection as $product) {
    $product->delete();
}

/** @var \Magento\CatalogInventory\Model\StockRegistryStorage $stockRegistryStorage */
$stockRegistryStorage = Bootstrap::getObjectManager()
    ->get(\Magento\CatalogInventory\Model\StockRegistryStorage::class);
$stockRegistryStorage->clean();

$registry->unregister('isSecureArea');
$registry->register('isSecureArea', false);
