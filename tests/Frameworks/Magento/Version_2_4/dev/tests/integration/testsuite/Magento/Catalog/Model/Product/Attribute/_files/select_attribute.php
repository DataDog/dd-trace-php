<?php
/**
 * "dropdown" fixture of product EAV attribute.
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/** @var \Magento\Eav\Model\Entity\Type $entityType */
$entityType = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
    \Magento\Eav\Model\Entity\Type::class
);
$entityType->loadByCode('catalog_product');
$defaultSetId = $entityType->getDefaultAttributeSetId();
/** @var \Magento\Eav\Model\Entity\Attribute\Set $defaultSet */
$defaultSet = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
    \Magento\Eav\Model\Entity\Attribute\Set::class
);
$defaultSet->load($defaultSetId);
$defaultGroupId = $defaultSet->getDefaultGroupId();
$optionData = ['value' => ['option_1' => [0 => 'Fixture Option']], 'order' => ['option_1' => 1]];

/** @var $attribute \Magento\Catalog\Model\ResourceModel\Eav\Attribute */
$attribute = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
    \Magento\Catalog\Model\ResourceModel\Eav\Attribute::class
);
$attribute->setAttributeCode(
    'select_attribute'
)->setEntityTypeId(
    $entityType->getEntityTypeId()
)->setAttributeGroupId(
    $defaultGroupId
)->setAttributeSetId(
    $defaultSetId
)->setFrontendInput(
    'select'
)->setFrontendLabel(
    'Select Attribute'
)->setBackendType(
    'int'
)->setIsUserDefined(
    1
)->setOption(
    $optionData
)->save();
