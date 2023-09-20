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

/** @var Magento\Cms\Api\PageRepositoryInterface $pageRepository */
$pageRepository = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(
    Magento\Cms\Api\PageRepositoryInterface::class
);

$pageRepository->deleteById('page-a');
$pageRepository->deleteById('page-b');
$pageRepository->deleteById('page-c');

/** @var \Magento\Catalog\Api\ProductRepositoryInterface $productRepository */
$productRepository = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()
    ->create(\Magento\Catalog\Api\ProductRepositoryInterface::class);

/** @var \Magento\UrlRewrite\Model\ResourceModel\UrlRewriteCollection $urlRewriteCollection */
$urlRewriteCollection = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()
    ->create(\Magento\UrlRewrite\Model\ResourceModel\UrlRewriteCollection::class);
$collection = $urlRewriteCollection
    ->addFieldToFilter('entity_type', 'custom')
    ->addFieldToFilter('target_path', ['page-a/', 'page-a', 'page-b', 'page-c'])
    ->load()
    ->walk('delete');

$registry->unregister('isSecureArea');
$registry->register('isSecureArea', false);
