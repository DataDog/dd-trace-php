<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

use Magento\TestFramework\Helper\Bootstrap;

/** @var \Magento\CatalogRule\Model\Rule $rule */
$rule = Bootstrap::getObjectManager()->get(\Magento\CatalogRule\Model\RuleFactory::class)->create();
$rule->loadPost([
    'name' => 'test_rule_one',
    'is_active' => '1',
    'stop_rules_processing' => 0,
    'website_ids' => [1],
    'customer_group_ids' => [0, 1],
    'discount_amount' => 5,
    'simple_action' => 'by_percent',
    'from_date' => '',
    'to_date' => '',
    'sort_order' => 0,
    'sub_is_enable' => 0,
    'sub_discount_amount' => 0,
    'conditions' => [
        '1' => [
            'type' => \Magento\CatalogRule\Model\Rule\Condition\Combine::class,
            'aggregator' => 'all',
            'value' => '1',
            'new_child' => '',
            'attribute' => null,
            'operator' => null,
            'is_value_processed' => null,
        ],
    ],
]);
$rule->save();
$rule = Bootstrap::getObjectManager()->get(\Magento\CatalogRule\Model\RuleFactory::class)->create();
$rule->loadPost([
        'name' => 'test_rule_two',
        'is_active' => '1',
        'stop_rules_processing' => 0,
        'website_ids' => [1],
        'customer_group_ids' => [0, 1],
        'discount_amount' => 2,
        'simple_action' => 'by_fixed',
        'from_date' => '',
        'to_date' => '',
        'sort_order' => 0,
        'sub_is_enable' => 0,
        'sub_discount_amount' => 0,
        'conditions' => [
            '1' => [
                'type' => \Magento\CatalogRule\Model\Rule\Condition\Combine::class,
                'aggregator' => 'all',
                'value' => '1',
                'new_child' => '',
                'attribute' => null,
                'operator' => null,
                'is_value_processed' => null,
            ],
        ],
    ]);
$rule->save();
