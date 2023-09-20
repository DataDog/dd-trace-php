<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

require __DIR__ . '/attribute_set_based_on_default_with_custom_group.php';

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ProductFactory;
use Magento\Store\Model\Store;
use Magento\TestFramework\Eav\Model\GetAttributeSetByName;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();
/** @var ProductRepositoryInterface $productRepository */
$productRepository = $objectManager->get(ProductRepositoryInterface::class);
/** @var ProductFactory $productFactory */
$productFactory = $objectManager->get(ProductFactory::class);
/** @var GetAttributeSetByName $attributeSet */
$attributeSet = $objectManager->get(GetAttributeSetByName::class);
$customAttributeSet = $attributeSet->execute('new_attribute_set');
$product = $productFactory->create();
$product
    ->setTypeId('simple')
    ->setAttributeSetId($customAttributeSet->getAttributeSetId())
    ->setWebsiteIds([1])
    ->setStoreId(Store::DEFAULT_STORE_ID)
    ->setName('Simple Product')
    ->setSku('simple')
    ->setPrice(10)
    ->setMetaTitle('meta title')
    ->setMetaKeyword('meta keyword')
    ->setMetaDescription('meta description')
    ->setVisibility(Visibility::VISIBILITY_BOTH)
    ->setStatus(Status::STATUS_ENABLED)
    ->setStockData(['use_config_manage_stock' => 1, 'qty' => 22, 'is_in_stock' => 1])
    ->setQty(22);
$productRepository->save($product);
