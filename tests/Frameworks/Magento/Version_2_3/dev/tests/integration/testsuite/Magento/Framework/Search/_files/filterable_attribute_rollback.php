<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
/* Create attribute */
/** @var $installer \Magento\Catalog\Setup\CategorySetup */
$installer = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
    \Magento\Catalog\Setup\CategorySetup::class,
    ['resourceName' => 'catalog_setup']
);
/** @var $attribute \Magento\Catalog\Model\ResourceModel\Eav\Attribute */
$attribute = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
    \Magento\Catalog\Model\ResourceModel\Eav\Attribute::class
);
$attribute->loadByCode(\Magento\Catalog\Model\Product::ENTITY, 'select_attribute');

/** @var $selectOptions \Magento\Eav\Model\ResourceModel\Entity\Attribute\Option\Collection */
$selectOptions = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
    \Magento\Eav\Model\ResourceModel\Entity\Attribute\Option\Collection::class
);
$registry = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(\Magento\Framework\Registry::class);

$registry->unregister('isSecureArea');
$registry->register('isSecureArea', true);

$selectOptions->setAttributeFilter($attribute->getId());
/* Delete simple products per each select(dropdown) option */
foreach ($selectOptions as $option) {
    /** @var $product \Magento\Catalog\Model\Product */
    $product = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
        \Magento\Catalog\Model\Product::class
    );
    $product = $product->loadByAttribute('sku', 'simple_product_' . $option->getId());
    if ($product->getId()) {
        $product->delete();
    }
}
if ($attribute->getId()) {
    $attribute->delete();
}

$attribute->loadByCode($installer->getEntityTypeId('catalog_product'), 'multiselect_attribute');
if ($attribute->getId()) {
    $attribute->delete();
}

$registry->unregister('isSecureArea');
$registry->register('isSecureArea', false);
