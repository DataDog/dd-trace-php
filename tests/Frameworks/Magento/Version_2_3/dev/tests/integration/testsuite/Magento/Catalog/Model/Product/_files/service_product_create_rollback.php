<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
use Magento\TestFramework\Helper\Bootstrap;

/** @var \Magento\Framework\Registry $registry */
$registry = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(\Magento\Framework\Registry::class);
$registry->unregister('isSecureArea');
$registry->register('isSecureArea', true);

/** @var \Magento\Catalog\Model\Product $productModel */
$productModel = Bootstrap::getObjectManager()->get(\Magento\Catalog\Model\Product::class);
$productModel->load($productModel->getIdBySku('psku-test-1'));
if ($productModel->getId()) {
    $productModel->delete();
}

/** @var \Magento\Catalog\Model\Product $productModel */
$productModel = Bootstrap::getObjectManager()->get(\Magento\Catalog\Model\Product::class);
$productModel->load($productModel->getIdBySku('psku-test-2'));
if ($productModel->getId()) {
    $productModel->delete();
}
