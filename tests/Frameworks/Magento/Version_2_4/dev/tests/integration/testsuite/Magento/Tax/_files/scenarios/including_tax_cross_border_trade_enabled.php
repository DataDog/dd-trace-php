<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

use Magento\Tax\Model\Calculation;
use Magento\Tax\Model\Config;
use Magento\Tax\Model\Sales\Total\Quote\SetupUtil;

$taxCalculationData['including_tax_cross_border_trade_enabled'] = [
    'config_data' => [
        SetupUtil::CONFIG_OVERRIDES => [
            Config::CONFIG_XML_PATH_APPLY_AFTER_DISCOUNT => 1,
            Config::CONFIG_XML_PATH_PRICE_INCLUDES_TAX => 1,
            Config::CONFIG_XML_PATH_CROSS_BORDER_TRADE_ENABLED => 1,
            Config::XML_PATH_ALGORITHM => Calculation::CALC_UNIT_BASE,
        ],
        SetupUtil::TAX_RATE_OVERRIDES => [
            SetupUtil::TAX_RATE_TX => 20,
            SetupUtil::TAX_STORE_RATE => 10,
        ],
        SetupUtil::TAX_RULE_OVERRIDES => [
        ],
    ],
    'quote_data' => [
        'billing_address' => [
            'region_id' => SetupUtil::REGION_TX,
        ],
        'shipping_address' => [
            'region_id' => SetupUtil::REGION_TX,
        ],
        'items' => [
            [
                'sku' => 'simple1',
                'price' => 9.99,
                'qty' => 2,
            ],
        ],
    ],
    'expected_results' => [
        'address_data' => [
            'subtotal' => 16.64,
            'base_subtotal' => 16.64,
            'subtotal_incl_tax' => 19.98,
            'base_subtotal_incl_tax' => 19.98,
            'tax_amount' => 3.34,
            'base_tax_amount' => 3.34,
            'shipping_amount' => 0,
            'base_shipping_amount' => 0,
            'shipping_incl_tax' => 0,
            'base_shipping_incl_tax' => 0,
            'shipping_taxable' => 0,
            'base_shipping_taxable' => 0,
            'shipping_tax_amount' => 0,
            'base_shipping_tax_amount' => 0,
            'discount_amount' => 0,
            'base_discount_amount' => 0,
            'discount_tax_compensation_amount' => 0,
            'base_discount_tax_compensation_amount' => 0,
            'shipping_discount_tax_compensation_amount' => 0,
            'base_shipping_discount_tax_compensation_amount' => 0,
            'grand_total' => 19.98,
            'base_grand_total' => 19.98,
        ],
        'items_data' => [
            'simple1' => [
                'row_total' => 16.64,
                'base_row_total' => 16.64,
                'tax_percent' => 20,
                'price' => 8.32,
                'base_price' => 8.32,
                'price_incl_tax' => 9.99,
                'base_price_incl_tax' => 9.99,
                'row_total_incl_tax' => 19.98,
                'base_row_total_incl_tax' => 19.98,
                'tax_amount' => 3.34,
                'base_tax_amount' => 3.34,
                'discount_amount' => 0,
                'base_discount_amount' => 0,
                'discount_percent' => 0,
                'discount_tax_compensation_amount' => 0,
                'base_discount_tax_compensation_amount' => 0,
            ],
        ],
    ],
];
