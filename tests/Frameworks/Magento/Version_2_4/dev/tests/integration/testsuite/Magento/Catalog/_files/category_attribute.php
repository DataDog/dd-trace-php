<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/** @var Attribute $attribute */

use Magento\Catalog\Model\Category\AttributeFactory;
use Magento\Catalog\Model\Category\Attribute;
use Magento\TestFramework\Helper\Bootstrap;

/** @var AttributeFactory $attributeFactory */
$attributeFactory = Bootstrap::getObjectManager()->get(AttributeFactory::class);
$attribute = $attributeFactory->create();
$attribute->setAttributeCode('test_attribute_code_666')
    ->setEntityTypeId(3)
    ->setIsGlobal(1)
    ->setIsUserDefined(1);
$attribute->save();
