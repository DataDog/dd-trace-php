<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/** @var $product \Magento\Catalog\Model\Product */
$objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();

/** @var \Magento\Eav\Model\Entity\Attribute\Set $attributeSet */
$attributeSet = $objectManager->create(\Magento\Eav\Model\Entity\Attribute\Set::class);

$entityType = $objectManager->create(\Magento\Eav\Model\Entity\Type::class)->loadByCode('catalog_product');
$defaultSetId = $objectManager->create(\Magento\Catalog\Model\Product::class)->getDefaultAttributeSetid();

$data = [
    'attribute_set_name' => 'attribute_set_with_media_attribute',
    'entity_type_id' => $entityType->getId(),
    'sort_order' => 200,
];

$attributeSet->setData($data);
$attributeSet->validate();
$attributeSet->save();
$attributeSet->initFromSkeleton($defaultSetId);
$attributeSet->save();

$attributeData = [
    'entity_type_id' => $entityType->getId(),
    'attribute_code' => 'funny_image',
    'frontend_input' => 'media_image',
    'frontend_label' => 'Funny image',
    'backend_type' => 'varchar',
    'is_required' => 0,
    'is_user_defined' => 1,
    'attribute_set_id' => $attributeSet->getId(),
    'attribute_group_id' => $attributeSet->getDefaultGroupId(),
];

/** @var \Magento\Catalog\Model\Entity\Attribute $attribute */
$attribute = $objectManager->create(\Magento\Catalog\Model\Entity\Attribute::class);
$attribute->setData($attributeData);
$attribute->save();
