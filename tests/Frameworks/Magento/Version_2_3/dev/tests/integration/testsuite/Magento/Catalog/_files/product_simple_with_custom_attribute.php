<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

include __DIR__ . '/product_simple_with_full_option_set.php';

/** @var \Magento\TestFramework\ObjectManager $objectManager */
$objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();

/** @var \Magento\Catalog\Api\ProductRepositoryInterface $productRepository */
$productRepository = $objectManager->create(\Magento\Catalog\Api\ProductRepositoryInterface::class);

/** @var $installer \Magento\Catalog\Setup\CategorySetup */
$installer = $objectManager->create(\Magento\Catalog\Setup\CategorySetup::class);
$entityModel = $objectManager->create(\Magento\Eav\Model\Entity::class);
$attributeSetId = $installer->getAttributeSetId('catalog_product', 'Default');
$entityTypeId = $entityModel->setType(\Magento\Catalog\Model\Product::ENTITY)->getTypeId();
$groupId = $installer->getDefaultAttributeGroupId($entityTypeId, $attributeSetId);

/** @var $attribute \Magento\Catalog\Model\ResourceModel\Eav\Attribute */
$attribute = $objectManager->create(\Magento\Catalog\Model\ResourceModel\Eav\Attribute::class);
$attribute->setAttributeCode(
    'attribute_code_custom'
)->setEntityTypeId(
    $entityTypeId
)->setIsVisible(true)->setFrontendInput(
    'text'
)->setFrontendLabel(
    'custom_attributes_frontend_label'
)->setAttributeGroupId(
    $groupId
)->setIsFilterable(
    1
)->setIsUserDefined(
    1
)->setBackendType(
    $attribute->getBackendTypeByInput($attribute->getFrontendInput())
)->save();

$product = $productRepository->get('simple', true);

$product->setCustomAttribute($attribute->getAttributeCode(), 'customAttributeValue');

$productRepository->save($product);
