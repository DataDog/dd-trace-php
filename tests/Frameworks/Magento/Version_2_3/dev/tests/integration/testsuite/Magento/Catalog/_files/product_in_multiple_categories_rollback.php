<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

/** @var \Magento\Framework\Registry $registry */
$registry = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(\Magento\Framework\Registry::class);
$registry->unregister('isSecureArea');
$registry->register('isSecureArea', true);

/** @var $product \Magento\Catalog\Model\Product */
$product = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(\Magento\Catalog\Model\Product::class);
$product->load(333);
if ($product->getId()) {
    $product->delete();
}

/** @var $product \Magento\Catalog\Model\Product */
$product = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(\Magento\Catalog\Model\Product::class);
$product->load(334);
if ($product->getId()) {
    $product->delete();
}
/** @var $category \Magento\Catalog\Model\Category */
$category = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(\Magento\Catalog\Model\Category::class);
$category->load(333);
if ($category->getId()) {
    $category->delete();
}

/** @var $category \Magento\Catalog\Model\Category */
$category = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(\Magento\Catalog\Model\Category::class);
$category->load(4);
if ($category->getId()) {
    $category->delete();
}
