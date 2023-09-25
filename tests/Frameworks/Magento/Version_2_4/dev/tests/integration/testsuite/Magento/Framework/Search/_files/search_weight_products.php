<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 *
 */

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();

/** @var ProductRepositoryInterface $productRepository */
$productRepository = $objectManager->create(ProductRepositoryInterface::class);

/** @var $productWithMatchInTitle \Magento\Catalog\Model\Product */
$productWithMatchInTitle = $objectManager->create(\Magento\Catalog\Model\Product::class);
$productWithMatchInTitle->isObjectNew(true);
$productWithMatchInTitle->setTypeId(\Magento\Catalog\Model\Product\Type::TYPE_SIMPLE)
    ->setId(1221)
    ->setAttributeSetId(4)
    ->setWebsiteIds([1])
    ->setName('Antarctica Lorem ipsum dolor sit amet, consectetur adipiscing elit')
    ->setSku('search_weight_1')
    ->setPrice(12)
    ->setWeight(1)
    ->setDescription('Lorem ipsum dolor sit amet, consectetur adipiscing elit')
    ->setVisibility(\Magento\Catalog\Model\Product\Visibility::VISIBILITY_BOTH)
    ->setStatus(\Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED)
    ->setCategoryIds([2])
    ->setStockData(
        [
            'use_config_manage_stock' => 1,
            'qty' => 100,
            'is_qty_decimal' => 0,
            'is_in_stock' => 1,
        ]
    );

$productRepository->save($productWithMatchInTitle);

$productWithMatchInDescription = $objectManager->create(\Magento\Catalog\Model\Product::class);
$productWithMatchInDescription->isObjectNew(true);
$productWithMatchInDescription->setTypeId(\Magento\Catalog\Model\Product\Type::TYPE_SIMPLE)
    ->setId(1222)
    ->setAttributeSetId(4)
    ->setWebsiteIds([1])
    ->setName('Lorem ipsum dolor sit amet, consectetur adipiscing elit')
    ->setSku('search_weight_2')
    ->setPrice(12)
    ->setWeight(1)
    ->setDescription('Lorem ipsum antarctica dolor sit amet, consectetur antarctica adipiscing elit')
    ->setVisibility(\Magento\Catalog\Model\Product\Visibility::VISIBILITY_BOTH)
    ->setStatus(\Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED)
    ->setCategoryIds([2])
    ->setStockData(
        [
            'use_config_manage_stock' => 1,
            'qty' => 100,
            'is_qty_decimal' => 0,
            'is_in_stock' => 1,
        ]
    );

$productRepository->save($productWithMatchInDescription);

/** @var \Magento\Catalog\Api\ProductAttributeRepositoryInterface $productAttributeRepository */
$productAttributeRepository = $objectManager->get(
    \Magento\Catalog\Api\ProductAttributeRepositoryInterface::class
);

/** @var \Magento\Catalog\Model\ResourceModel\Eav\Attribute $nameAttribute */
$nameAttribute = $productAttributeRepository->get('name');
$nameAttribute->setSearchWeight(1);
$productAttributeRepository->save($nameAttribute);

$descriptionAttribute = $productAttributeRepository->get('description');
$descriptionAttribute->setSearchWeight(1);
$productAttributeRepository->save($descriptionAttribute);
