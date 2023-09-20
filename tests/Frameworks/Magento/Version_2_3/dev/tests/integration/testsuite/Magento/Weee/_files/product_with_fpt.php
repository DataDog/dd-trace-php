<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

use Magento\Catalog\Model\Product;
use Magento\TestFramework\Helper\Bootstrap;

/** @var \Magento\Catalog\Setup\CategorySetup $installer */
$installer = Bootstrap::getObjectManager()->create(
    \Magento\Catalog\Setup\CategorySetup::class
);
$attributeSetId = $installer->getAttributeSetId('catalog_product', 'Default');
$entityModel = Bootstrap::getObjectManager()->create(\Magento\Eav\Model\Entity::class);
$entityTypeId = $entityModel->setType(Product::ENTITY)->getTypeId();
$groupId = $installer->getDefaultAttributeGroupId($entityTypeId, $attributeSetId);

/** @var \Magento\Catalog\Model\ResourceModel\Eav\Attribute $attribute */
$attribute = Bootstrap::getObjectManager()->create(
    \Magento\Catalog\Model\ResourceModel\Eav\Attribute::class
);
$attribute->setAttributeCode(
    'fpt_for_all'
)->setEntityTypeId(
    $entityTypeId
)->setAttributeGroupId(
    $groupId
)->setAttributeSetId(
    $attributeSetId
)->setFrontendLabel(
    'fpt_for_all_front_label'
)->setFrontendInput(
    'weee'
)->setIsUserDefined(
    1
)->save();

/** @var Product $product */
$product = Bootstrap::getObjectManager()->create(Product::class);
$product->setTypeId(
    'simple'
)->setAttributeSetId(
    $attributeSetId
)->setStoreId(
    1
)->setWebsiteIds(
    [1]
)->setVisibility(
    \Magento\Catalog\Model\Product\Visibility::VISIBILITY_BOTH
)->setStatus(
    \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED
)->setStockData(
    ['qty' => 100, 'is_in_stock' => 1, 'manage_stock' => 1]
)->setName(
    'Simple Product FPT'
)->setSku(
    'simple-with-ftp'
)->setPrice(
    100
)->setFptForAll(
    [['website_id' => 0, 'country' => 'US', 'state' => 0, 'price' => 12.70, 'delete' => '']]
)->save();
