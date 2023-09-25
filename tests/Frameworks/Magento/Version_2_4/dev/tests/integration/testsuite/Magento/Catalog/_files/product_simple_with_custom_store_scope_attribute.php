<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

use Magento\TestFramework\Helper\Bootstrap;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ProductFactory;
use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Catalog\Setup\CategorySetup;
use Magento\Eav\Model\Entity;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Type;

/** @var \Magento\TestFramework\ObjectManager $objectManager */
$objectManager = Bootstrap::getObjectManager();
/** @var ProductRepositoryInterface $productRepository */
$productRepository = $objectManager->get(ProductRepositoryInterface::class);
/** @var ProductFactory $productFactory */
$productFactory = $objectManager->get(ProductFactory::class);
/** @var ProductAttributeRepositoryInterface $attributeRepository */
$attributeRepository = $objectManager->get(ProductAttributeRepositoryInterface::class);


/** @var $installer CategorySetup */
$installer = $objectManager->create(CategorySetup::class);
$entityModel = $objectManager->create(Entity::class);
$attributeSetId = $installer->getAttributeSetId(Product::ENTITY, 'Default');
$entityTypeId = $entityModel->setType(Product::ENTITY)
    ->getTypeId();
$groupId = $installer->getDefaultAttributeGroupId($entityTypeId, $attributeSetId);

/** @var ProductAttributeInterface $attribute */
$attribute = $objectManager->create(ProductAttributeInterface::class);

$attribute->setAttributeCode('store_scoped_attribute_code')
    ->setEntityTypeId($entityTypeId)
    ->setIsVisible(true)
    ->setFrontendInput('text')
    ->setIsFilterable(1)
    ->setIsUserDefined(1)
    ->setUsedInProductListing(1)
    ->setBackendType('varchar')
    ->setIsUsedInGrid(1)
    ->setIsVisibleInGrid(1)
    ->setIsFilterableInGrid(1)
    ->setFrontendLabel('nobody cares')
    ->setAttributeGroupId($groupId)
    ->setAttributeSetId(4);

$attributeRepository->save($attribute);

$product = $productFactory->create()
    ->setTypeId(Type::TYPE_SIMPLE)
    ->setAttributeSetId(4)
    ->setName('Simple With Store Scoped Custom Attribute')
    ->setSku('simple_with_store_scoped_custom_attribute')
    ->setPrice(100)
    ->setVisibility(1)
    ->setStockData(
        [
            'use_config_manage_stock'   => 1,
            'qty'                       => 100,
            'is_in_stock'               => 1,
        ]
    )
    ->setStatus(1);
$product->setCustomAttribute('store_scoped_attribute_code', 'default_value');
$productRepository->save($product);
