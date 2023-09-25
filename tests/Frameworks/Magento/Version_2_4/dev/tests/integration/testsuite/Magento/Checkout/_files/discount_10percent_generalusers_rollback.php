<?php
/**
 * SalesRule 10% discount coupon
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/** @var \Magento\SalesRule\Model\Rule $salesRule */
$salesRule = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(\Magento\SalesRule\Model\Rule::class);
/** @var int $salesRuleId */
$salesRuleId = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(\Magento\Framework\Registry::class)
    ->registry('Magento/Checkout/_file/discount_10percent_generalusers');
$salesRule->load($salesRuleId);
$salesRule->delete();
