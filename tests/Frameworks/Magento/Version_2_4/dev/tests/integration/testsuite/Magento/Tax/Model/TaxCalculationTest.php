<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Tax\Model;

use Magento\Tax\Api\Data\TaxClassKeyInterface;
use Magento\Tax\Model\TaxClass\Key;
use Magento\TestFramework\Helper\Bootstrap;

/**
 * @magentoDbIsolation enabled
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class TaxCalculationTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var float
     */
    private const EPSILON = 0.0000000001;

    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var \Magento\Tax\Api\TaxCalculationInterface
     */
    private $taxCalculationService;

    /**
     * Tax Details Factory
     *
     * @var \Magento\Tax\Api\Data\QuoteDetailsInterfaceFactory
     */
    private $quoteDetailsFactory;

    /**
     * Array of default tax classes ids
     *
     * Key is class name
     *
     * @var int[]
     */
    private $taxClassIds;

    /**
     * Array of default tax rates ids.
     *
     * Key is rate percentage as string.
     *
     * @var int[]
     */
    private $taxRates;

    /**
     * Array of default tax rules ids.
     *
     * Key is rule code.
     *
     * @var int[]
     */
    private $taxRules;

    /**
     * Helps in creating required tax rules.
     *
     * @var TaxRuleFixtureFactory
     */
    private $taxRuleFixtureFactory;

    /**
     * @var \Magento\Framework\Api\DataObjectHelper
     */
    private $dataObjectHelper;

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
        $this->quoteDetailsFactory = $this->objectManager->create(
            \Magento\Tax\Api\Data\QuoteDetailsInterfaceFactory::class
        );
        $this->dataObjectHelper = $this->objectManager->create(\Magento\Framework\Api\DataObjectHelper::class);
        $this->taxCalculationService = $this->objectManager->get(\Magento\Tax\Api\TaxCalculationInterface::class);
        $this->taxRuleFixtureFactory = new TaxRuleFixtureFactory();

        $this->setUpDefaultRules();
    }

    protected function tearDown(): void
    {
        $this->tearDownDefaultRules();
    }

    /**
     * @magentoConfigFixture current_store tax/calculation/algorithm UNIT_BASE_CALCULATION
     * @dataProvider calculateUnitBasedDataProvider
     */
    public function testCalculateTaxUnitBased($quoteDetailsData, $expected)
    {
        $quoteDetailsData = $this->performTaxClassSubstitution($quoteDetailsData);
        $quoteDetails = $this->quoteDetailsFactory->create();
        $this->dataObjectHelper->populateWithArray(
            $quoteDetails,
            $quoteDetailsData,
            \Magento\Tax\Api\Data\QuoteDetailsInterface::class
        );

        $taxDetails = $this->taxCalculationService->calculateTax($quoteDetails, 1);
        $this->assertEqualsWithDelta($expected, $this->convertObjectToArray($taxDetails), self::EPSILON);
    }

    /**
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function calculateUnitBasedDataProvider()
    {
        $baseQuote = $this->getBaseQuoteData();
        $oneProduct = $baseQuote;
        $oneProduct['items'][] = [
            'code' => 'sku_1',
            'type' => 'product',
            'quantity' => 2,
            'unit_price' => 10,
            'tax_class_key' => [
                Key::KEY_TYPE => TaxClassKeyInterface::TYPE_NAME,
                Key::KEY_VALUE => 'DefaultProductClass',
            ],
        ];
        $oneProductResults = [
            'subtotal' => 20,
            'tax_amount' => 1.5,
            'discount_tax_compensation_amount' => 0,
            'applied_taxes' => [
                [
                    'amount' => 1.5,
                    'percent' => 7.5,
                    'rates' => [
                        [
                            'code' => 'US - 42 - 7.5',
                            'title' => 'US - 42 - 7.5',
                            'percent' => 7.5,
                        ],
                    ],
                    'tax_rate_key' => 'US - 42 - 7.5',
                ],
            ],
            'items' => [
                'sku_1' => [
                    'code' => 'sku_1',
                    'row_tax' => 1.5,
                    'price' => 10,
                    'price_incl_tax' => 10.75,
                    'row_total' => 20,
                    'row_total_incl_tax' => 21.5,
                    'type' => 'product',
                    'tax_percent' => 7.5,
                    'discount_tax_compensation_amount' => 0,
                    'associated_item_code' => null,
                    'applied_taxes' => [
                        'US - 42 - 7.5' => [
                            'amount' => 1.5,
                            'percent' => 7.5,
                            'tax_rate_key' => 'US - 42 - 7.5',
                            'rates' => [
                                'US - 42 - 7.5' => [
                                    'percent' => 7.5,
                                    'code' => 'US - 42 - 7.5',
                                    'title' => 'US - 42 - 7.5',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $weeeProduct = $baseQuote;
        $weeeProduct['items'][] = [
            'code' => 'sequence-1',
            'type' => 'product',
            'quantity' => 1,
            'unit_price' => 10,
            'tax_class_key' => 'WeeeProductClass',
        ];
        $weeeProduct['items'][] = [
            'code' => 'weee1-Recycling Fee',
            'type' => 'weee',
            'quantity' => 1,
            'unit_price' => 7,
            'tax_class_key' => 'WeeeProductClass',
            'associated_item_code' => 'sequence-1'
        ];
        $weeeProductResults = [
            'subtotal' => 17,
            'tax_amount' => 1.4,
            'discount_tax_compensation_amount' => 0,
            'applied_taxes' => [
                [
                    'amount' => 1.4,
                    'percent' => 8.25,
                    'rates' => [
                        [
                            'code' => 'US - 42 - 8.25',
                            'title' => 'US - 42 - 8.25',
                            'percent' => 8.25,
                        ],
                    ],
                    'tax_rate_key' => 'US - 42 - 8.25',
                ],
            ],
            'items' => [
                'sequence-1' => [
                    'code' => 'sequence-1',
                    'row_tax' => 0.83,
                    'price' => 10,
                    'price_incl_tax' => 10.83,
                    'row_total' => 10,
                    'row_total_incl_tax' => 10.83,
                    'type' => 'product',
                    'tax_percent' => 8.25,
                    'discount_tax_compensation_amount' => 0,
                    'associated_item_code' => null,
                    'applied_taxes' => [
                        'US - 42 - 8.25' => [
                            'amount' => 0.83,
                            'percent' => 8.25,
                            'tax_rate_key' => 'US - 42 - 8.25',
                            'rates' => [
                                'US - 42 - 8.25' => [
                                    'percent' => 8.25,
                                    'code' => 'US - 42 - 8.25',
                                    'title' => 'US - 42 - 8.25',
                                ],
                            ],
                        ],
                    ],
                ],
                'weee1-Recycling Fee' => [
                    'code' => 'weee1-Recycling Fee',
                    'row_tax' => 0.57,
                    'price' => 7,
                    'price_incl_tax' => 7.57,
                    'row_total' => 7,
                    'row_total_incl_tax' => 7.57,
                    'type' => 'weee',
                    'tax_percent' => 8.25,
                    'discount_tax_compensation_amount' => 0,
                    'associated_item_code' => 'sequence-1',
                    'applied_taxes' => [
                        'US - 42 - 8.25' => [
                            'amount' => 0.57,
                            'percent' => 8.25,
                            'tax_rate_key' => 'US - 42 - 8.25',
                            'rates' => [
                                'US - 42 - 8.25' => [
                                    'percent' => 8.25,
                                    'code' => 'US - 42 - 8.25',
                                    'title' => 'US - 42 - 8.25',
                                ],
                            ],
                        ],
                    ],
                ]
            ],
        ];

        $weeeProducts = $baseQuote;
        $weeeProducts['items'][] = [
            'code' => 'sequence-1',
            'type' => 'product',
            'quantity' => 2,
            'unit_price' => 10,
            'tax_class_key' => 'WeeeProductClass',
        ];
        $weeeProducts['items'][] = [
            'code' => 'weee1-Recycling Fee',
            'type' => 'weee',
            'quantity' => 2,
            'unit_price' => 7,
            'tax_class_key' => 'WeeeProductClass',
            'associated_item_code' => 'sequence-1'
        ];
        $weeeProductsResults = [
            'subtotal' => 34,
            'tax_amount' => 2.80,
            'discount_tax_compensation_amount' => 0,
            'applied_taxes' => [
                [
                    'amount' => 2.80,
                    'percent' => 8.25,
                    'rates' => [
                        [
                            'code' => 'US - 42 - 8.25',
                            'title' => 'US - 42 - 8.25',
                            'percent' => 8.25,
                        ],
                    ],
                    'tax_rate_key' => 'US - 42 - 8.25',
                ],
            ],
            'items' => [
                'sequence-1' => [
                    'code' => 'sequence-1',
                    'row_tax' => 1.66,
                    'price' => 10,
                    'price_incl_tax' => 10.83,
                    'row_total' => 20,
                    'row_total_incl_tax' => 21.66,
                    'type' => 'product',
                    'tax_percent' => 8.25,
                    'discount_tax_compensation_amount' => 0,
                    'associated_item_code' => null,
                    'applied_taxes' => [
                        'US - 42 - 8.25' => [
                            'amount' => 1.66,
                            'percent' => 8.25,
                            'tax_rate_key' => 'US - 42 - 8.25',
                            'rates' => [
                                'US - 42 - 8.25' => [
                                    'percent' => 8.25,
                                    'code' => 'US - 42 - 8.25',
                                    'title' => 'US - 42 - 8.25',
                                ],
                            ],
                        ],
                    ],
                ],
                'weee1-Recycling Fee' => [
                    'code' => 'weee1-Recycling Fee',
                    'row_tax' => 1.14,
                    'price' => 7,
                    'price_incl_tax' => 7.57,
                    'row_total' => 14,
                    'row_total_incl_tax' => 15.14,
                    'type' => 'weee',
                    'tax_percent' => 8.25,
                    'discount_tax_compensation_amount' => 0,
                    'associated_item_code' => 'sequence-1',
                    'applied_taxes' => [
                        'US - 42 - 8.25' => [
                            'amount' => 1.14,
                            'percent' => 8.25,
                            'tax_rate_key' => 'US - 42 - 8.25',
                            'rates' => [
                                'US - 42 - 8.25' => [
                                    'percent' => 8.25,
                                    'code' => 'US - 42 - 8.25',
                                    'title' => 'US - 42 - 8.25',
                                ],
                            ],
                        ],
                    ],
                ]
            ],
        ];

        $multiWeeeProducts = $baseQuote;
        $multiWeeeProducts['items'][] = [
            'code' => 'sequence-1',
            'type' => 'product',
            'quantity' => 2,
            'unit_price' => 10,
            'tax_class_key' => 'WeeeProductClass',
        ];
        $multiWeeeProducts['items'][] = [
            'code' => 'weee1-Recycling Fee',
            'type' => 'weee',
            'quantity' => 2,
            'unit_price' => 7,
            'tax_class_key' => 'WeeeProductClass',
            'associated_item_code' => 'sequence-1'
        ];
        $multiWeeeProducts['items'][] = [
            'code' => 'sequence-2',
            'type' => 'product',
            'quantity' => 2,
            'unit_price' => 10,
            'tax_class_key' => 'WeeeProductClass',
        ];
        $multiWeeeProducts['items'][] = [
            'code' => 'weee2-Recycling Fee',
            'type' => 'weee',
            'quantity' => 2,
            'unit_price' => 7,
            'tax_class_key' => 'WeeeProductClass',
            'associated_item_code' => 'sequence-2'
        ];
        $multiWeeeProductsResults = [
            'subtotal' => 68,
            'tax_amount' => 5.60,
            'discount_tax_compensation_amount' => 0,
            'applied_taxes' => [
                [
                    'amount' => 5.60,
                    'percent' => 8.25,
                    'rates' => [
                        [
                            'code' => 'US - 42 - 8.25',
                            'title' => 'US - 42 - 8.25',
                            'percent' => 8.25,
                        ],
                    ],
                    'tax_rate_key' => 'US - 42 - 8.25',
                ],
            ],
            'items' => [
                'sequence-1' => [
                    'code' => 'sequence-1',
                    'row_tax' => 1.66,
                    'price' => 10,
                    'price_incl_tax' => 10.83,
                    'row_total' => 20,
                    'row_total_incl_tax' => 21.66,
                    'type' => 'product',
                    'tax_percent' => 8.25,
                    'discount_tax_compensation_amount' => 0,
                    'associated_item_code' => null,
                    'applied_taxes' => [
                        'US - 42 - 8.25' => [
                            'amount' => 1.66,
                            'percent' => 8.25,
                            'tax_rate_key' => 'US - 42 - 8.25',
                            'rates' => [
                                'US - 42 - 8.25' => [
                                    'percent' => 8.25,
                                    'code' => 'US - 42 - 8.25',
                                    'title' => 'US - 42 - 8.25',
                                ],
                            ],
                        ],
                    ],
                ],
                'weee1-Recycling Fee' => [
                    'code' => 'weee1-Recycling Fee',
                    'row_tax' => 1.14,
                    'price' => 7,
                    'price_incl_tax' => 7.57,
                    'row_total' => 14,
                    'row_total_incl_tax' => 15.14,
                    'type' => 'weee',
                    'tax_percent' => 8.25,
                    'discount_tax_compensation_amount' => 0,
                    'associated_item_code' => 'sequence-1',
                    'applied_taxes' => [
                        'US - 42 - 8.25' => [
                            'amount' => 1.14,
                            'percent' => 8.25,
                            'tax_rate_key' => 'US - 42 - 8.25',
                            'rates' => [
                                'US - 42 - 8.25' => [
                                    'percent' => 8.25,
                                    'code' => 'US - 42 - 8.25',
                                    'title' => 'US - 42 - 8.25',
                                ],
                            ],
                        ],
                    ],
                ],
                'sequence-2' => [
                    'code' => 'sequence-2',
                    'row_tax' => 1.66,
                    'price' => 10,
                    'price_incl_tax' => 10.83,
                    'row_total' => 20,
                    'row_total_incl_tax' => 21.66,
                    'type' => 'product',
                    'tax_percent' => 8.25,
                    'discount_tax_compensation_amount' => 0,
                    'associated_item_code' => null,
                    'applied_taxes' => [
                        'US - 42 - 8.25' => [
                            'amount' => 1.66,
                            'percent' => 8.25,
                            'tax_rate_key' => 'US - 42 - 8.25',
                            'rates' => [
                                'US - 42 - 8.25' => [
                                    'percent' => 8.25,
                                    'code' => 'US - 42 - 8.25',
                                    'title' => 'US - 42 - 8.25',
                                ],
                            ],
                        ],
                    ],
                ],
                'weee2-Recycling Fee' => [
                    'code' => 'weee2-Recycling Fee',
                    'row_tax' => 1.14,
                    'price' => 7,
                    'price_incl_tax' => 7.57,
                    'row_total' => 14,
                    'row_total_incl_tax' => 15.14,
                    'type' => 'weee',
                    'tax_percent' => 8.25,
                    'discount_tax_compensation_amount' => 0,
                    'associated_item_code' => 'sequence-2',
                    'applied_taxes' => [
                        'US - 42 - 8.25' => [
                            'amount' => 1.14,
                            'percent' => 8.25,
                            'tax_rate_key' => 'US - 42 - 8.25',
                            'rates' => [
                                'US - 42 - 8.25' => [
                                    'percent' => 8.25,
                                    'code' => 'US - 42 - 8.25',
                                    'title' => 'US - 42 - 8.25',
                                ],
                            ],
                        ],
                    ],
                ]
            ],
        ];

        $oneProductInclTax = $baseQuote;
        $oneProductInclTax['items'][] = [
            'code' => 'sku_1',
            'type' => 'product',
            'quantity' => 2,
            'unit_price' => 10.75,
            'tax_class_key' => 'DefaultProductClass',
            'is_tax_included' => true,
        ];
        $oneProductInclTaxResults = $oneProductResults;

        $oneProductInclTaxDiffRate = $baseQuote;
        $oneProductInclTaxDiffRate['items'][] = [
            'code' => 'sku_1',
            'type' => 'product',
            'quantity' => 2,
            'unit_price' => 11,
            'tax_class_key' => 'HigherProductClass',
            'is_tax_included' => true,
        ];
        $oneProductInclTaxDiffRateResults = [
            'subtotal' => 20,
            'tax_amount' => 4.4,
            'discount_tax_compensation_amount' => 0,
            'applied_taxes' => [
                [
                    'amount' => 4.4,
                    'percent' => 22,
                    'rates' => [
                        [
                            'code' => 'US - 42 - 22',
                            'title' => 'US - 42 - 22',
                            'percent' => 22,
                        ],
                    ],
                    'tax_rate_key' => 'US - 42 - 22',
                ],
            ],
            'items' => [
                'sku_1' => [
                    'code' => 'sku_1',
                    'row_tax' => 4.4,
                    'price' => 10,
                    'price_incl_tax' => 12.2,
                    'row_total' => 20,
                    'row_total_incl_tax' => 24.4,
                    'type' => 'product',
                    'tax_percent' => 22.0,
                    'discount_tax_compensation_amount' => 0,
                    'associated_item_code' => null,
                    'applied_taxes' => [
                        'US - 42 - 22' => [
                            'amount' => 4.4,
                            'percent' => 22,
                            'tax_rate_key' => 'US - 42 - 22',
                            'rates' => [
                                'US - 42 - 22' => [
                                    'percent' => 22,
                                    'code' => 'US - 42 - 22',
                                    'title' => 'US - 42 - 22',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $twoProducts = $baseQuote;
        $twoProducts['items'] = [
            [
                'code' => 'sku_1',
                'type' => 'product',
                'quantity' => 2,
                'unit_price' => 10,
                'tax_class_key' => 'DefaultProductClass',
            ],
            [
                'code' => 'sku_2',
                'type' => 'product',
                'quantity' => 20,
                'unit_price' => 11,
                'tax_class_key' => 'DefaultProductClass',
            ],
        ];
        $twoProductsResults = [
            'subtotal' => 240,
            'tax_amount' => 18.1,
            'discount_tax_compensation_amount' => 0,
            'applied_taxes' => [
                [
                    'amount' => 18.1,
                    'percent' => 7.5,
                    'rates' => [
                        [
                            'code' => 'US - 42 - 7.5',
                            'title' => 'US - 42 - 7.5',
                            'percent' => 7.5,
                        ],
                    ],
                    'tax_rate_key' => 'US - 42 - 7.5',
                ],
            ],
            'items' => [
                'sku_1' => [
                    'code' => 'sku_1',
                    'row_tax' => 1.5,
                    'price' => 10,
                    'price_incl_tax' => 10.75,
                    'row_total' => 20,
                    'row_total_incl_tax' => 21.5,
                    'type' => 'product',
                    'tax_percent' => 7.5,
                    'discount_tax_compensation_amount' => 0,
                    'associated_item_code' => null,
                    'applied_taxes' => [
                        'US - 42 - 7.5' => [
                            'amount' => 1.5,
                            'percent' => 7.5,
                            'tax_rate_key' => 'US - 42 - 7.5',
                            'rates' => [
                                'US - 42 - 7.5' => [
                                    'percent' => 7.5,
                                    'code' => 'US - 42 - 7.5',
                                    'title' => 'US - 42 - 7.5',
                                ],
                            ],
                        ],
                    ],
                ],
                'sku_2' =>                 [
                    'code' => 'sku_2',
                    'row_tax' => 16.6,
                    'price' => 11,
                    'price_incl_tax' => 11.83,
                    'row_total' => 220,
                    'row_total_incl_tax' => 236.6,
                    'type' => 'product',
                    'tax_percent' => 7.5,
                    'discount_tax_compensation_amount' => 0,
                    'associated_item_code' => null,
                    'applied_taxes' => [
                        'US - 42 - 7.5' => [
                            'amount' => 16.6,
                            'percent' => 7.5,
                            'tax_rate_key' => 'US - 42 - 7.5',
                            'rates' => [
                                'US - 42 - 7.5' => [
                                    'percent' => 7.5,
                                    'code' => 'US - 42 - 7.5',
                                    'title' => 'US - 42 - 7.5',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $twoProductsInclTax = $baseQuote;
        $twoProductsInclTax['items'] = [
            [
                'code' => 'sku_1',
                'type' => 'product',
                'quantity' => 2,
                'unit_price' => 10.75,
                'row_total' => 21.5,
                'tax_class_key' => 'DefaultProductClass',
                'is_tax_included' => true,
            ],
            [
                'code' => 'sku_2',
                'type' => 'product',
                'quantity' => 20,
                'unit_price' => 11.83,
                'row_total' => 236.6,
                'tax_class_key' => 'DefaultProductClass',
                'is_tax_included' => true,
            ],
        ];
        $twoProductInclTaxResults = $twoProductsResults;

        $bundleProduct = $baseQuote;
        $bundleProduct['items'][] = [
            'code' => 'sku_1',
            'type' => 'product',
            'quantity' => 1,
            'unit_price' => 10,
            'tax_class_key' => 'DefaultProductClass',
            'parent_code' => 'bundle',
        ];
        $bundleProduct['items'][] = [
            'code' => 'bundle',
            'type' => 'product',
            'quantity' => 2,
            'unit_price' => 0,
            'tax_class_key' => 'DefaultProductClass',
        ];
        $bundleProductResults = [
            'subtotal' => 20,
            'tax_amount' => 1.5,
            'discount_tax_compensation_amount' => 0,
            'applied_taxes' => [
                [
                    'amount' => 1.5,
                    'percent' => 7.5,
                    'rates' => [
                        [
                            'code' => 'US - 42 - 7.5',
                            'title' => 'US - 42 - 7.5',
                            'percent' => 7.5,
                        ],
                    ],
                    'tax_rate_key' => 'US - 42 - 7.5',
                ],
            ],
            'items' => [
                'sku_1' => [
                    'code' => 'sku_1',
                    'row_tax' => 1.5,
                    'price' => 10,
                    'price_incl_tax' => 10.75,
                    'row_total' => 20,
                    'row_total_incl_tax' => 21.5,
                    'type' => 'product',
                    'tax_percent' => 7.5,
                    'discount_tax_compensation_amount' => 0,
                    'associated_item_code' => null,
                    'applied_taxes' => [
                        'US - 42 - 7.5' => [
                            'amount' => 1.5,
                            'percent' => 7.5,
                            'tax_rate_key' => 'US - 42 - 7.5',
                            'rates' => [
                                'US - 42 - 7.5' => [
                                    'percent' => 7.5,
                                    'code' => 'US - 42 - 7.5',
                                    'title' => 'US - 42 - 7.5',
                                ],
                            ],
                        ],
                    ],
                ],
                'bundle' => [
                    'price' => 10,
                    'price_incl_tax' => 10.75,
                    'row_total' => 20,
                    'row_total_incl_tax' => 21.5,
                    'row_tax' => 1.5,
                    'code' => 'bundle',
                    'type' => 'product',
                ],
            ],
        ];

        return [
            'one product' => [
                'quote_details' => $oneProduct,
                'expected_tax_details' => $oneProductResults,
            ],
            'weee product' => [
                'quote_details' => $weeeProduct,
                'expected_tax_details' => $weeeProductResults,
            ],
            'weee products' => [
                'quote_details' => $weeeProducts,
                'expected_tax_details' => $weeeProductsResults,
            ],
            'multi weee products' => [
                'quote_details' => $multiWeeeProducts,
                'expected_tax_details' => $multiWeeeProductsResults,
            ],
            'one product, tax included' => [
                'quote_details' => $oneProductInclTax,
                'expected_tax_details' => $oneProductInclTaxResults,
            ],
            'one product, tax included but differs from store rate' => [
                'quote_details' => $oneProductInclTaxDiffRate,
                'expected_tax_details' => $oneProductInclTaxDiffRateResults,
            ],
            'two products' => [
                'quote_details' => $twoProducts,
                'expected_tax_details' => $twoProductsResults,
            ],
            'two products, tax included' => [
                'quote_details' => $twoProductsInclTax,
                'expected_tax_details' => $twoProductInclTaxResults,
            ],
            'bundle product' => [
                'quote_details' => $bundleProduct,
                'expected_tax_details' => $bundleProductResults,
            ],
        ];
    }

    /**
     * @dataProvider calculateTaxTotalBasedDataProvider
     * @magentoConfigFixture current_store tax/calculation/algorithm TOTAL_BASE_CALCULATION
     */
    public function testCalculateTaxTotalBased($quoteDetailsData, $expectedTaxDetails, $storeId = null)
    {
        $quoteDetailsData = $this->performTaxClassSubstitution($quoteDetailsData);
        $quoteDetails = $this->quoteDetailsFactory->create();
        $this->dataObjectHelper->populateWithArray(
            $quoteDetails,
            $quoteDetailsData,
            \Magento\Tax\Api\Data\QuoteDetailsInterface::class
        );

        $taxDetails = $this->taxCalculationService->calculateTax($quoteDetails, $storeId);

        $this->assertEquals($expectedTaxDetails, $this->convertObjectToArray($taxDetails));
    }

    public function calculateTaxTotalBasedDataProvider()
    {
        return array_merge(
            $this->calculateTaxNoTaxInclDataProvider(),
            $this->calculateTaxTaxInclDataProvider(),
            $this->calculateTaxRoundingDataProvider()
        );
    }

    /**
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function calculateTaxNoTaxInclDataProvider()
    {
        $prodNoTaxInclBase = [
            'quote_details' => [
                'shipping_address' => [
                    'postcode' => '55555',
                    'country_id' => 'US',
                    'region' => ['region_id' => 42],
                ],
                'items' => [
                    [
                        'code' => 'code',
                        'type' => 'type',
                        'quantity' => 1,
                        'unit_price' => 10.0,
                        'is_tax_included' => false,
                    ],
                ],
                'customer_tax_class_key' => 'DefaultCustomerClass',
            ],
            'expected_tax_details' => [
                'subtotal' => 10.0,
                'tax_amount' => 0.0,
                'discount_tax_compensation_amount' => 0.0,
                'applied_taxes' => [],
                'items' => [],
            ],
            'store_id' => null,
        ];

        $prodQuoteDetailItemBase = [
            'code' => 'code',
            'type' => 'type',
            'quantity' => 1,
            'unit_price' => 10.0,
            'is_tax_included' => false,
        ];

        $quoteDetailAppliedTaxesBase = [
            [
                'amount' => 0.75,
                'percent' => 7.5,
                'rates' => [
                    [
                        'code' => 'US - 42 - 7.5',
                        'title' => 'US - 42 - 7.5',
                        'percent' => 7.5,
                    ],
                ],
                'tax_rate_key' => 'US - 42 - 7.5',
            ],
        ];

        $itemDetailAppliedTaxesBase = [
            'US - 42 - 7.5' => [
                'amount' => 0.75,
                'percent' => 7.5,
                'tax_rate_key' => 'US - 42 - 7.5',
                'rates' => [
                    'US - 42 - 7.5' => [
                        'percent' => 7.5,
                        'code' => 'US - 42 - 7.5',
                        'title' => 'US - 42 - 7.5',
                    ],
                ],
            ],
        ];

        $quoteDetailItemWithDefaultProductTaxClass = $prodQuoteDetailItemBase;
        $quoteDetailItemWithDefaultProductTaxClass['tax_class_key'] = 'DefaultProductClass';

        $prodExpectedItemWithNoProductTaxClass = [
            'code' => [
                'code' => 'code',
                'row_tax' => 0,
                'price' => 10.0,
                'price_incl_tax' => 10.0,
                'row_total' => 10.0,
                'row_total_incl_tax' => 10.0,
                'type' => 'type',
                'tax_percent' => 0,
                'discount_tax_compensation_amount' => 0,
                'associated_item_code' => null,
                'applied_taxes' => [],
            ],
        ];

        $itemAppliedTaxes = $itemDetailAppliedTaxesBase;
        $prodExpectedItemWithDefaultProductTaxClass = [
            'code' => [
                'code' => 'code',
                'row_tax' => 0.75,
                'price' => 10.0,
                'price_incl_tax' => 10.75,
                'row_total' => 10.0,
                'row_total_incl_tax' => 10.75,
                'type' => 'type',
                'tax_percent' => 7.5,
                'discount_tax_compensation_amount' => 0,
                'associated_item_code' => null,
                'applied_taxes' => $itemAppliedTaxes,
            ],
        ];

        $prodWithStoreIdWithTaxClassId = $prodNoTaxInclBase;
        $prodWithStoreIdWithoutTaxClassId = $prodNoTaxInclBase;
        $prodWithoutStoreIdWithTaxClassId = $prodNoTaxInclBase;
        $prodWithoutStoreIdWithoutTaxClassId = $prodNoTaxInclBase;

        $prodWithStoreIdWithTaxClassId['store_id'] = 1;
        $prodWithStoreIdWithTaxClassId['quote_details']['items'][] = $quoteDetailItemWithDefaultProductTaxClass;
        $prodWithStoreIdWithTaxClassId['expected_tax_details']['tax_amount'] = 0.75;
        $prodWithStoreIdWithTaxClassId['expected_tax_details']['applied_taxes'] = $quoteDetailAppliedTaxesBase;
        $prodWithStoreIdWithTaxClassId['expected_tax_details']['items'] =
            $prodExpectedItemWithDefaultProductTaxClass;

        $prodWithStoreIdWithoutTaxClassId['store_id'] = 1;
        $prodWithStoreIdWithoutTaxClassId['quote_details']['items'][] = $prodQuoteDetailItemBase;
        $prodWithStoreIdWithoutTaxClassId['expected_tax_details']['items'] =
            $prodExpectedItemWithNoProductTaxClass;

        $prodWithoutStoreIdWithTaxClassId['quote_details']['items'][] =
            $quoteDetailItemWithDefaultProductTaxClass;
        $prodWithoutStoreIdWithTaxClassId['expected_tax_details']['tax_amount'] = 0.75;
        $prodWithoutStoreIdWithTaxClassId['expected_tax_details']['applied_taxes'] = $quoteDetailAppliedTaxesBase;
        $prodWithoutStoreIdWithTaxClassId['expected_tax_details']['items'] =
            $prodExpectedItemWithDefaultProductTaxClass;

        $prodWithoutStoreIdWithoutTaxClassId['quote_details']['items'][] = $prodQuoteDetailItemBase;
        $prodWithoutStoreIdWithoutTaxClassId['expected_tax_details']['items'] =
            $prodExpectedItemWithNoProductTaxClass;

        return [
            'product with store id, with tax class id' => $prodWithStoreIdWithTaxClassId,
            'product with store id, without tax class id' => $prodWithStoreIdWithoutTaxClassId,
            'product without store id, with tax class id' => $prodWithoutStoreIdWithTaxClassId,
            'product without store id, without tax class id' => $prodWithoutStoreIdWithoutTaxClassId,
        ];
    }

    /**
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function calculateTaxTaxInclDataProvider()
    {
        $productTaxInclBase = [
            'quote_details' => [
                'shipping_address' => [
                    'postcode' => '55555',
                    'country_id' => 'US',
                    'region' => ['region_id' => 42],
                ],
                'items' => [
                    [
                        'code' => 'code',
                        'type' => 'type',
                        'quantity' => 1,
                        'unit_price' => 10.0,
                        'is_tax_included' => true,
                    ],
                ],
                'customer_tax_class_key' => [
                    Key::KEY_TYPE => TaxClassKeyInterface::TYPE_NAME,
                    Key::KEY_VALUE => 'DefaultCustomerClass',
                ],
            ],
            'expected_tax_details' => [
                'subtotal' => 10.0,
                'tax_amount' => 0.0,
                'discount_tax_compensation_amount' => 0.0,
                'applied_taxes' => [],
                'items' => [],
            ],
            'store_id' => null,
        ];

        $productTaxInclQuoteDetailItemBase = [
            'code' => 'code',
            'type' => 'type',
            'quantity' => 1,
            'unit_price' => 10.0,
            'is_tax_included' => true,
        ];

        $quoteDetailTaxInclItemWithDefaultProductTaxClass = $productTaxInclQuoteDetailItemBase;
        $quoteDetailTaxInclItemWithDefaultProductTaxClass['tax_class_key'] = 'DefaultProductClass';

        $productTaxInclExpectedItemWithNoProductTaxClass = [
            'code' => [
                'code' => 'code',
                'row_tax' => 0,
                'price' => 10.0,
                'price_incl_tax' => 10.0,
                'row_total' => 10.0,
                'row_total_incl_tax' => 10.0,
                'type' => 'type',
                'tax_percent' => 0,
                'discount_tax_compensation_amount' => 0,
                'associated_item_code' => null,
                'applied_taxes' => [],
            ],
        ];

        $quoteDetailAppliedTaxesBase = [
            [
                'amount' => 0.70,
                'percent' => 7.5,
                'rates' => [
                    [
                        'code' => 'US - 42 - 7.5',
                        'title' => 'US - 42 - 7.5',
                        'percent' => 7.5,
                    ],
                ],
                'tax_rate_key' => 'US - 42 - 7.5',
            ],
        ];

        $productTaxInclExpectedItemWithDefaultProductTaxClass = [
            'code' => [
                'code' => 'code',
                'row_tax' => 0.70,
                'price' => 9.30,
                'price_incl_tax' => 10.00,
                'row_total' => 9.30,
                'row_total_incl_tax' => 10.00,
                'type' => 'type',
                'tax_percent' => 7.5,
                'discount_tax_compensation_amount' => 0,
                'associated_item_code' => null,
                'applied_taxes' => [
                    'US - 42 - 7.5' => [
                        'amount' => 0.7,
                        'percent' => 7.5,
                        'tax_rate_key' => 'US - 42 - 7.5',
                        'rates' => [
                            'US - 42 - 7.5' => [
                                'percent' => 7.5,
                                'code' => 'US - 42 - 7.5',
                                'title' => 'US - 42 - 7.5',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $productInclTaxWithStoreIdWithTaxClassId = $productTaxInclBase;
        $productInclTaxWithStoreIdWithoutTaxClassId = $productTaxInclBase;
        $productInclTaxWithoutStoreIdWithTaxClassId = $productTaxInclBase;
        $productInclTaxWithoutStoreIdWithoutTaxClassId = $productTaxInclBase;

        $productInclTaxWithStoreIdWithTaxClassId['store_id'] = 1;
        $productInclTaxWithStoreIdWithTaxClassId['quote_details']['items'][] =
            $quoteDetailTaxInclItemWithDefaultProductTaxClass;
        $productInclTaxWithStoreIdWithTaxClassId['expected_tax_details']['tax_amount'] = 0.70;
        $productInclTaxWithStoreIdWithTaxClassId['expected_tax_details']['subtotal'] = 9.30;
        $productInclTaxWithStoreIdWithTaxClassId['expected_tax_details']['applied_taxes'] =
            $quoteDetailAppliedTaxesBase;
        $productInclTaxWithStoreIdWithTaxClassId['expected_tax_details']['items'] =
            $productTaxInclExpectedItemWithDefaultProductTaxClass;

        $productInclTaxWithStoreIdWithoutTaxClassId['store_id'] = 1;
        $productInclTaxWithStoreIdWithoutTaxClassId['quote_details']['items'][] =
            $productTaxInclQuoteDetailItemBase;
        $productInclTaxWithStoreIdWithoutTaxClassId['expected_tax_details']['items'] =
            $productTaxInclExpectedItemWithNoProductTaxClass;

        $productInclTaxWithoutStoreIdWithTaxClassId['quote_details']['items'][] =
            $quoteDetailTaxInclItemWithDefaultProductTaxClass;
        $productInclTaxWithoutStoreIdWithTaxClassId['expected_tax_details']['tax_amount'] = 0.70;
        $productInclTaxWithoutStoreIdWithTaxClassId['expected_tax_details']['subtotal'] = 9.30;
        $productInclTaxWithoutStoreIdWithTaxClassId['expected_tax_details']['applied_taxes'] =
            $quoteDetailAppliedTaxesBase;
        $productInclTaxWithoutStoreIdWithTaxClassId['expected_tax_details']['items'] =
            $productTaxInclExpectedItemWithDefaultProductTaxClass;

        $productInclTaxWithoutStoreIdWithoutTaxClassId['quote_details']['items'][] = $productTaxInclQuoteDetailItemBase;
        $productInclTaxWithoutStoreIdWithoutTaxClassId['expected_tax_details']['items'] =
            $productTaxInclExpectedItemWithNoProductTaxClass;

        return [
            'product incl tax with store id, with tax class id' => $productInclTaxWithStoreIdWithTaxClassId,
            'product incl tax with store id, without tax class id' => $productInclTaxWithStoreIdWithoutTaxClassId,
            'product incl tax without store id, with tax class id' => $productInclTaxWithoutStoreIdWithTaxClassId,
            'product incl tax without store id, without tax class id' => $productInclTaxWithoutStoreIdWithoutTaxClassId,
        ];
    }

    /**
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function calculateTaxRoundingDataProvider()
    {
        $prodRoundingNoTaxInclBase = [
            'quote_details' => [
                'shipping_address' => [
                    'postcode' => '55555',
                    'country_id' => 'US',
                    'region' => ['region_id' => 42],
                ],
                'items' => [
                    [
                        'code' => 'code',
                        'type' => 'type',
                        'quantity' => 2,
                        'unit_price' => 7.97,
                        'is_tax_included' => false,
                    ],
                ],
                'customer_tax_class_key' => 'DefaultCustomerClass',
            ],
            'expected_tax_details' => [
                'subtotal' => 15.94,
                'tax_amount' => 0.0,
                'discount_tax_compensation_amount' => 0.0,
                'applied_taxes' => [],
                'items' => [],
            ],
            'store_id' => null,
        ];

        $prodQuoteDetailItemBase = [
            'code' => 'code',
            'type' => 'type',
            'quantity' => 2,
            'unit_price' => 7.97,
            'is_tax_included' => false,
        ];

        $quoteDetailItemWithDefaultProductTaxClass = $prodQuoteDetailItemBase;
        $quoteDetailItemWithDefaultProductTaxClass['tax_class_key'] = 'DefaultProductClass';

        $quoteDetailAppliedTaxesBase = [
            [
                'amount' => 1.20,
                'percent' => 7.5,
                'rates' => [
                    [
                        'code' => 'US - 42 - 7.5',
                        'title' => 'US - 42 - 7.5',
                        'percent' => 7.5,
                    ],
                ],
                'tax_rate_key' => 'US - 42 - 7.5',
            ],
        ];

        $prodExpectedItemWithNoProductTaxClass = [
            'code' => [
                'code' => 'code',
                'row_tax' => 0,
                'price' => 7.97,
                'price_incl_tax' => 7.97,
                'row_total' => 15.94,
                'row_total_incl_tax' => 15.94,
                'type' => 'type',
                'tax_percent' => 0,
                'discount_tax_compensation_amount' => 0,
                'associated_item_code' => null,
                'applied_taxes' => [],
            ],
        ];

        $prodExpectedItemWithDefaultProductTaxClass = [
            'code' => [
                'code' => 'code',
                'row_tax' => 1.20,
                'price' => 7.97,
                'price_incl_tax' => 8.57,
                'row_total' => 15.94,
                'row_total_incl_tax' => 17.14,
                'type' => 'type',
                'tax_percent' => 7.5,
                'discount_tax_compensation_amount' => 0,
                'associated_item_code' => null,
                'applied_taxes' => [
                    'US - 42 - 7.5' => [
                        'amount' => 1.2,
                        'percent' => 7.5,
                        'tax_rate_key' => 'US - 42 - 7.5',
                        'rates' => [
                            'US - 42 - 7.5' => [
                                'percent' => 7.5,
                                'code' => 'US - 42 - 7.5',
                                'title' => 'US - 42 - 7.5',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $prodWithStoreIdWithTaxClassId = $prodRoundingNoTaxInclBase;
        $prodWithStoreIdWithoutTaxClassId = $prodRoundingNoTaxInclBase;
        $prodWithoutStoreIdWithTaxClassId = $prodRoundingNoTaxInclBase;
        $prodWithoutStoreIdWithoutTaxClassId = $prodRoundingNoTaxInclBase;

        $prodWithStoreIdWithTaxClassId['store_id'] = 1;
        $prodWithStoreIdWithTaxClassId['quote_details']['items'][] = $quoteDetailItemWithDefaultProductTaxClass;
        $prodWithStoreIdWithTaxClassId['expected_tax_details']['tax_amount'] = 1.20;
        $prodWithStoreIdWithTaxClassId['expected_tax_details']['applied_taxes'] = $quoteDetailAppliedTaxesBase;
        $prodWithStoreIdWithTaxClassId['expected_tax_details']['items'] =
            $prodExpectedItemWithDefaultProductTaxClass;

        $prodWithStoreIdWithoutTaxClassId['store_id'] = 1;
        $prodWithStoreIdWithoutTaxClassId['quote_details']['items'][] = $prodQuoteDetailItemBase;
        $prodWithStoreIdWithoutTaxClassId['expected_tax_details']['items'] =
            $prodExpectedItemWithNoProductTaxClass;

        $prodWithoutStoreIdWithTaxClassId['quote_details']['items'][] =
            $quoteDetailItemWithDefaultProductTaxClass;
        $prodWithoutStoreIdWithTaxClassId['expected_tax_details']['tax_amount'] = 1.20;
        $prodWithoutStoreIdWithTaxClassId['expected_tax_details']['applied_taxes'] =
            $quoteDetailAppliedTaxesBase;
        $prodWithoutStoreIdWithTaxClassId['expected_tax_details']['items'] =
            $prodExpectedItemWithDefaultProductTaxClass;

        $prodWithoutStoreIdWithoutTaxClassId['quote_details']['items'][] = $prodQuoteDetailItemBase;
        $prodWithoutStoreIdWithoutTaxClassId['expected_tax_details']['items'] =
            $prodExpectedItemWithNoProductTaxClass;

        return [
            'rounding product with store id, with tax class id' => $prodWithStoreIdWithTaxClassId,
            'rounding product with store id, without tax class id' => $prodWithStoreIdWithoutTaxClassId,
            'rounding product without store id, with tax class id' => $prodWithoutStoreIdWithTaxClassId,
            'rounding product without store id, without tax class id' => $prodWithoutStoreIdWithoutTaxClassId,
        ];
    }

    /**
     * @magentoDbIsolation enabled
     * @dataProvider calculateTaxRowBasedDataProvider
     * @magentoConfigFixture default_store tax/calculation/algorithm ROW_BASE_CALCULATION
     */
    public function testCalculateTaxRowBased($quoteDetailsData, $expectedTaxDetails)
    {
        $quoteDetailsData = $this->performTaxClassSubstitution($quoteDetailsData);
        $quoteDetails = $this->quoteDetailsFactory->create();
        $this->dataObjectHelper->populateWithArray(
            $quoteDetails,
            $quoteDetailsData,
            \Magento\Tax\Api\Data\QuoteDetailsInterface::class
        );

        $taxDetails = $this->taxCalculationService->calculateTax($quoteDetails);

        $this->assertEqualsWithDelta($expectedTaxDetails, $this->convertObjectToArray($taxDetails), self::EPSILON);
    }

    /**
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function calculateTaxRowBasedDataProvider()
    {
        $baseQuote = $this->getBaseQuoteData();

        $oneProduct = $baseQuote;
        $oneProduct['items'][] = [
            'code' => 'sku_1',
            'type' => 'product',
            'quantity' => 10,
            'unit_price' => 1,
            'tax_class_key' => 'DefaultProductClass',
        ];
        $oneProductResults = [
            'subtotal' => 10,
            'tax_amount' => 0.75,
            'discount_tax_compensation_amount' => 0,
            'applied_taxes' => [
                [
                    'amount' => 0.75,
                    'percent' => 7.5,
                    'rates' => [
                        [
                            'code' => 'US - 42 - 7.5',
                            'title' => 'US - 42 - 7.5',
                            'percent' => 7.5,
                        ],
                    ],
                    'tax_rate_key' => 'US - 42 - 7.5',
                ],
            ],
            'items' => [
                'sku_1' => [
                    'code' => 'sku_1',
                    'row_tax' => 0.75,
                    'price' => 1,
                    'price_incl_tax' => 1.08,
                    'row_total' => 10,
                    'row_total_incl_tax' => 10.75,
                    'type' => 'product',
                    'tax_percent' => 7.5,
                    'discount_tax_compensation_amount' => 0,
                    'associated_item_code' => null,
                    'applied_taxes' => [
                        'US - 42 - 7.5' => [
                            'amount' => 0.75,
                            'percent' => 7.5,
                            'tax_rate_key' => 'US - 42 - 7.5',
                            'rates' => [
                                'US - 42 - 7.5' => [
                                    'percent' => 7.5,
                                    'code' => 'US - 42 - 7.5',
                                    'title' => 'US - 42 - 7.5',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $weeeProduct = $baseQuote;
        $weeeProduct['items'][] = [
            'code' => 'sequence-1',
            'type' => 'product',
            'quantity' => 1,
            'unit_price' => 10,
            'tax_class_key' => 'WeeeProductClass',
        ];
        $weeeProduct['items'][] = [
            'code' => 'weee1-Recycling Fee',
            'type' => 'weee',
            'quantity' => 1,
            'unit_price' => 7,
            'tax_class_key' => 'WeeeProductClass',
            'associated_item_code' => 'sequence-1'
        ];
        $weeeProductResults = [
            'subtotal' => 17,
            'tax_amount' => 1.4,
            'discount_tax_compensation_amount' => 0,
            'applied_taxes' => [
                [
                    'amount' => 1.4,
                    'percent' => 8.25,
                    'rates' => [
                        [
                            'code' => 'US - 42 - 8.25',
                            'title' => 'US - 42 - 8.25',
                            'percent' => 8.25,
                        ],
                    ],
                    'tax_rate_key' => 'US - 42 - 8.25',
                ],
            ],
            'items' => [
                'sequence-1' => [
                    'code' => 'sequence-1',
                    'row_tax' => 0.83,
                    'price' => 10,
                    'price_incl_tax' => 10.83,
                    'row_total' => 10,
                    'row_total_incl_tax' => 10.83,
                    'type' => 'product',
                    'tax_percent' => 8.25,
                    'discount_tax_compensation_amount' => 0,
                    'associated_item_code' => null,
                    'applied_taxes' => [
                        'US - 42 - 8.25' => [
                            'amount' => 0.83,
                            'percent' => 8.25,
                            'tax_rate_key' => 'US - 42 - 8.25',
                            'rates' => [
                                'US - 42 - 8.25' => [
                                    'percent' => 8.25,
                                    'code' => 'US - 42 - 8.25',
                                    'title' => 'US - 42 - 8.25',
                                ],
                            ],
                        ],
                    ],
                ],
                'weee1-Recycling Fee' => [
                    'code' => 'weee1-Recycling Fee',
                    'row_tax' => 0.57,
                    'price' => 7,
                    'price_incl_tax' => 7.57,
                    'row_total' => 7,
                    'row_total_incl_tax' => 7.57,
                    'type' => 'weee',
                    'tax_percent' => 8.25,
                    'discount_tax_compensation_amount' => 0,
                    'associated_item_code' => 'sequence-1',
                    'applied_taxes' => [
                        'US - 42 - 8.25' => [
                            'amount' => 0.57,
                            'percent' => 8.25,
                            'tax_rate_key' => 'US - 42 - 8.25',
                            'rates' => [
                                'US - 42 - 8.25' => [
                                    'percent' => 8.25,
                                    'code' => 'US - 42 - 8.25',
                                    'title' => 'US - 42 - 8.25',
                                ],
                            ],
                        ],
                    ],
                ]
            ],
        ];

        $weeeProducts = $baseQuote;
        $weeeProducts['items'][] = [
            'code' => 'sequence-1',
            'type' => 'product',
            'quantity' => 2,
            'unit_price' => 10,
            'tax_class_key' => 'WeeeProductClass',
        ];
        $weeeProducts['items'][] = [
            'code' => 'weee1-Recycling Fee',
            'type' => 'weee',
            'quantity' => 2,
            'unit_price' => 7,
            'tax_class_key' => 'WeeeProductClass',
            'associated_item_code' => 'sequence-1'
        ];
        $weeeProductsResults = [
            'subtotal' => 34,
            'tax_amount' => 2.81,
            'discount_tax_compensation_amount' => 0,
            'applied_taxes' => [
                [
                    'amount' => 2.81,
                    'percent' => 8.25,
                    'rates' => [
                        [
                            'code' => 'US - 42 - 8.25',
                            'title' => 'US - 42 - 8.25',
                            'percent' => 8.25,
                        ],
                    ],
                    'tax_rate_key' => 'US - 42 - 8.25',
                ],
            ],
            'items' => [
                'sequence-1' => [
                    'code' => 'sequence-1',
                    'row_tax' => 1.65,
                    'price' => 10,
                    'price_incl_tax' => 10.83,
                    'row_total' => 20,
                    'row_total_incl_tax' => 21.65,
                    'type' => 'product',
                    'tax_percent' => 8.25,
                    'discount_tax_compensation_amount' => 0,
                    'associated_item_code' => null,
                    'applied_taxes' => [
                        'US - 42 - 8.25' => [
                            'amount' => 1.65,
                            'percent' => 8.25,
                            'tax_rate_key' => 'US - 42 - 8.25',
                            'rates' => [
                                'US - 42 - 8.25' => [
                                    'percent' => 8.25,
                                    'code' => 'US - 42 - 8.25',
                                    'title' => 'US - 42 - 8.25',
                                ],
                            ],
                        ],
                    ],
                ],
                'weee1-Recycling Fee' => [
                    'code' => 'weee1-Recycling Fee',
                    'row_tax' => 1.16,
                    'price' => 7,
                    'price_incl_tax' => 7.58,
                    'row_total' => 14,
                    'row_total_incl_tax' => 15.16,
                    'type' => 'weee',
                    'tax_percent' => 8.25,
                    'discount_tax_compensation_amount' => 0,
                    'associated_item_code' => 'sequence-1',
                    'applied_taxes' => [
                        'US - 42 - 8.25' => [
                            'amount' => 1.16,
                            'percent' => 8.25,
                            'tax_rate_key' => 'US - 42 - 8.25',
                            'rates' => [
                                'US - 42 - 8.25' => [
                                    'percent' => 8.25,
                                    'code' => 'US - 42 - 8.25',
                                    'title' => 'US - 42 - 8.25',
                                ],
                            ],
                        ],
                    ],
                ]
            ],
        ];

        $multiWeeeProducts = $baseQuote;
        $multiWeeeProducts['items'][] = [
            'code' => 'sequence-1',
            'type' => 'product',
            'quantity' => 2,
            'unit_price' => 10,
            'tax_class_key' => 'WeeeProductClass',
        ];
        $multiWeeeProducts['items'][] = [
            'code' => 'weee1-Recycling Fee',
            'type' => 'weee',
            'quantity' => 2,
            'unit_price' => 7,
            'tax_class_key' => 'WeeeProductClass',
            'associated_item_code' => 'sequence-1'
        ];
        $multiWeeeProducts['items'][] = [
            'code' => 'sequence-2',
            'type' => 'product',
            'quantity' => 2,
            'unit_price' => 10,
            'tax_class_key' => 'WeeeProductClass',
        ];
        $multiWeeeProducts['items'][] = [
            'code' => 'weee2-Recycling Fee',
            'type' => 'weee',
            'quantity' => 2,
            'unit_price' => 7,
            'tax_class_key' => 'WeeeProductClass',
            'associated_item_code' => 'sequence-2'
        ];
        $multiWeeeProductsResults = [
            'subtotal' => 68,
            'tax_amount' => 5.62,
            'discount_tax_compensation_amount' => 0,
            'applied_taxes' => [
                [
                    'amount' => 5.62,
                    'percent' => 8.25,
                    'rates' => [
                        [
                            'code' => 'US - 42 - 8.25',
                            'title' => 'US - 42 - 8.25',
                            'percent' => 8.25,
                        ],
                    ],
                    'tax_rate_key' => 'US - 42 - 8.25',
                ],
            ],
            'items' => [
                'sequence-1' => [
                    'code' => 'sequence-1',
                    'row_tax' => 1.65,
                    'price' => 10,
                    'price_incl_tax' => 10.83,
                    'row_total' => 20,
                    'row_total_incl_tax' => 21.65,
                    'type' => 'product',
                    'tax_percent' => 8.25,
                    'discount_tax_compensation_amount' => 0,
                    'associated_item_code' => null,
                    'applied_taxes' => [
                        'US - 42 - 8.25' => [
                            'amount' => 1.65,
                            'percent' => 8.25,
                            'tax_rate_key' => 'US - 42 - 8.25',
                            'rates' => [
                                'US - 42 - 8.25' => [
                                    'percent' => 8.25,
                                    'code' => 'US - 42 - 8.25',
                                    'title' => 'US - 42 - 8.25',
                                ],
                            ],
                        ],
                    ],
                ],
                'weee1-Recycling Fee' => [
                    'code' => 'weee1-Recycling Fee',
                    'row_tax' => 1.16,
                    'price' => 7,
                    'price_incl_tax' => 7.58,
                    'row_total' => 14,
                    'row_total_incl_tax' => 15.16,
                    'type' => 'weee',
                    'tax_percent' => 8.25,
                    'discount_tax_compensation_amount' => 0,
                    'associated_item_code' => 'sequence-1',
                    'applied_taxes' => [
                        'US - 42 - 8.25' => [
                            'amount' => 1.16,
                            'percent' => 8.25,
                            'tax_rate_key' => 'US - 42 - 8.25',
                            'rates' => [
                                'US - 42 - 8.25' => [
                                    'percent' => 8.25,
                                    'code' => 'US - 42 - 8.25',
                                    'title' => 'US - 42 - 8.25',
                                ],
                            ],
                        ],
                    ],
                ],
                'sequence-2' => [
                    'code' => 'sequence-2',
                    'row_tax' => 1.65,
                    'price' => 10,
                    'price_incl_tax' => 10.83,
                    'row_total' => 20,
                    'row_total_incl_tax' => 21.65,
                    'type' => 'product',
                    'tax_percent' => 8.25,
                    'discount_tax_compensation_amount' => 0,
                    'associated_item_code' => null,
                    'applied_taxes' => [
                        'US - 42 - 8.25' => [
                            'amount' => 1.65,
                            'percent' => 8.25,
                            'tax_rate_key' => 'US - 42 - 8.25',
                            'rates' => [
                                'US - 42 - 8.25' => [
                                    'percent' => 8.25,
                                    'code' => 'US - 42 - 8.25',
                                    'title' => 'US - 42 - 8.25',
                                ],
                            ],
                        ],
                    ],
                ],
                'weee2-Recycling Fee' => [
                    'code' => 'weee2-Recycling Fee',
                    'row_tax' => 1.16,
                    'price' => 7,
                    'price_incl_tax' => 7.58,
                    'row_total' => 14,
                    'row_total_incl_tax' => 15.16,
                    'type' => 'weee',
                    'tax_percent' => 8.25,
                    'discount_tax_compensation_amount' => 0,
                    'associated_item_code' => 'sequence-2',
                    'applied_taxes' => [
                        'US - 42 - 8.25' => [
                            'amount' => 1.16,
                            'percent' => 8.25,
                            'tax_rate_key' => 'US - 42 - 8.25',
                            'rates' => [
                                'US - 42 - 8.25' => [
                                    'percent' => 8.25,
                                    'code' => 'US - 42 - 8.25',
                                    'title' => 'US - 42 - 8.25',
                                ],
                            ],
                        ],
                    ],
                ]
            ],
        ];

        $oneProductInclTax = $baseQuote;
        $oneProductInclTax['items'][] = [
            'code' => 'sku_1',
            'type' => 'product',
            'quantity' => 10,
            'unit_price' => 1.0,
            'tax_class_key' => 'DefaultProductClass',
            'is_tax_included' => true,
        ];
        $oneProductInclTaxResults = [
            'subtotal' => 9.3,
            'tax_amount' => 0.7,
            'discount_tax_compensation_amount' => 0,
            'applied_taxes' => [
                [
                    'amount' => 0.7,
                    'percent' => 7.5,
                    'tax_rate_key' => 'US - 42 - 7.5',
                    'rates' => [
                        [
                            'percent' => 7.5,
                            'code' => 'US - 42 - 7.5',
                            'title' => 'US - 42 - 7.5',
                        ],
                    ],
                ],
            ],
            'items' => [
                'sku_1' => [
                    'code' => 'sku_1',
                    'row_tax' => .7,
                    'price' => 0.93,
                    'price_incl_tax' => 1.0,
                    'row_total' => 9.3,
                    'row_total_incl_tax' => 10,
                    'type' => 'product',
                    'tax_percent' => 7.5,
                    'discount_tax_compensation_amount' => 0,
                    'associated_item_code' => null,
                    'applied_taxes' => [
                        'US - 42 - 7.5' => [
                            'amount' => 0.7,
                            'percent' => 7.5,
                            'tax_rate_key' => 'US - 42 - 7.5',
                            'rates' => [
                                'US - 42 - 7.5' => [
                                    'percent' => 7.5,
                                    'code' => 'US - 42 - 7.5',
                                    'title' => 'US - 42 - 7.5',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $oneProductInclTaxDiffRate = $baseQuote;
        $oneProductInclTaxDiffRate['items'][] = [
            'code' => 'sku_1',
            'type' => 'product',
            'quantity' => 9,
            'unit_price' => 0.33, // this is including the store tax of 10%. Pre tax is 0.3
            'tax_class_key' => [
                Key::KEY_TYPE => TaxClassKeyInterface::TYPE_NAME,
                Key::KEY_VALUE => 'HigherProductClass',
            ],
            'is_tax_included' => true,
        ];
        $oneProductInclTaxDiffRateResults = [
            'subtotal' => 2.73,
            'tax_amount' => 0.6,
            'discount_tax_compensation_amount' => 0,
            'applied_taxes' => [
                [
                    'amount' => 0.6,
                    'percent' => 22,
                    'rates' => [
                        [
                            'percent' => 22,
                            'code' => 'US - 42 - 22',
                            'title' => 'US - 42 - 22',
                        ],
                    ],
                    'tax_rate_key' => 'US - 42 - 22',
                ],
            ],
            'items' => [
                'sku_1' => [
                    'code' => 'sku_1',
                    'row_tax' => 0.6,
                    'price' => 0.3,
                    'price_incl_tax' => 0.37,
                    'row_total' => 2.73,
                    'row_total_incl_tax' => 3.33,
                    'type' => 'product',
                    'tax_percent' => 22.0,
                    'discount_tax_compensation_amount' => 0,
                    'associated_item_code' => null,
                    'applied_taxes' => [
                        'US - 42 - 22' => [
                            'amount' => 0.6,
                            'percent' => 22,
                            'tax_rate_key' => 'US - 42 - 22',
                            'rates' => [
                                'US - 42 - 22' => [
                                    'percent' => 22,
                                    'code' => 'US - 42 - 22',
                                    'title' => 'US - 42 - 22',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $twoProducts = $baseQuote;
        $twoProducts['items'] = [
            [
                'code' => 'sku_1',
                'type' => 'product',
                'quantity' => 10,
                'unit_price' => 1,
                'tax_class_key' => 'DefaultProductClass',
            ],
            [
                'code' => 'sku_2',
                'type' => 'product',
                'quantity' => 20,
                'unit_price' => 11,
                'tax_class_key' => 'DefaultProductClass',
            ],
        ];
        $twoProductsResults = [
            'subtotal' => 230,
            'tax_amount' => 17.25,
            'discount_tax_compensation_amount' => 0,
            'applied_taxes' => [
                [
                    'amount' => 17.25,
                    'percent' => 7.5,
                    'tax_rate_key' => 'US - 42 - 7.5',
                    'rates' => [
                        [
                            'percent' => 7.5,
                            'code' => 'US - 42 - 7.5',
                            'title' => 'US - 42 - 7.5',
                        ],
                    ],
                ],
            ],
            'items' => [
                'sku_1' => [
                    'code' => 'sku_1',
                    'row_tax' => .75,
                    'price' => 1,
                    'price_incl_tax' => 1.08,
                    'row_total' => 10,
                    'row_total_incl_tax' => 10.75,
                    'type' => 'product',
                    'tax_percent' => 7.5,
                    'discount_tax_compensation_amount' => 0,
                    'associated_item_code' => null,
                    'applied_taxes' => [
                        'US - 42 - 7.5' => [
                            'amount' => 0.75,
                            'percent' => 7.5,
                            'tax_rate_key' => 'US - 42 - 7.5',
                            'rates' => [
                                'US - 42 - 7.5' => [
                                    'percent' => 7.5,
                                    'code' => 'US - 42 - 7.5',
                                    'title' => 'US - 42 - 7.5',
                                ],
                            ],
                        ],
                    ],
                ],
                'sku_2' => [
                    'code' => 'sku_2',
                    'row_tax' => 16.5,
                    'price' => 11,
                    'price_incl_tax' => 11.83,
                    'row_total' => 220,
                    'row_total_incl_tax' => 236.5,
                    'type' => 'product',
                    'tax_percent' => 7.5,
                    'discount_tax_compensation_amount' => 0,
                    'associated_item_code' => null,
                    'applied_taxes' => [
                        'US - 42 - 7.5' => [
                            'amount' => 16.5,
                            'percent' => 7.5,
                            'tax_rate_key' => 'US - 42 - 7.5',
                            'rates' => [
                                'US - 42 - 7.5' => [
                                    'percent' => 7.5,
                                    'code' => 'US - 42 - 7.5',
                                    'title' => 'US - 42 - 7.5',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $twoProductsInclTax = $baseQuote;
        $twoProductsInclTax['items'] = [
            [
                'code' => 'sku_1',
                'type' => 'product',
                'quantity' => 10,
                'unit_price' => 0.98,
                'tax_class_key' => 'DefaultProductClass',
                'is_tax_included' => true,
            ],
            [
                'code' => 'sku_2',
                'type' => 'product',
                'quantity' => 20,
                'unit_price' => 11.99,
                'tax_class_key' => 'DefaultProductClass',
                'is_tax_included' => true,
            ],
        ];
        $twoProductInclTaxResults = [
            'subtotal' => 232.19,
            'tax_amount' => 17.41,
            'discount_tax_compensation_amount' => 0,
            'applied_taxes' => [
                [
                    'amount' => 17.41,
                    'percent' => 7.5,
                    'tax_rate_key' => 'US - 42 - 7.5',
                    'rates' => [
                        [
                            'percent' => 7.5,
                            'code' => 'US - 42 - 7.5',
                            'title' => 'US - 42 - 7.5',
                        ],
                    ],
                ],
            ],
            'items' => [
                'sku_1' => [
                    'code' => 'sku_1',
                    'row_tax' => .68,
                    'price' => 0.91,
                    'price_incl_tax' => 0.98,
                    'row_total' => 9.12,
                    'row_total_incl_tax' => 9.8,
                    'type' => 'product',
                    'tax_percent' => 7.5,
                    'discount_tax_compensation_amount' => 0,
                    'associated_item_code' => null,
                    'applied_taxes' => [
                        'US - 42 - 7.5' => [
                            'amount' => 0.68,
                            'percent' => 7.5,
                            'tax_rate_key' => 'US - 42 - 7.5',
                            'rates' => [
                                'US - 42 - 7.5' => [
                                    'percent' => 7.5,
                                    'code' => 'US - 42 - 7.5',
                                    'title' => 'US - 42 - 7.5',
                                ],
                            ],
                        ],
                    ],
                ],
                'sku_2' => [
                    'code' => 'sku_2',
                    'row_tax' => 16.73,
                    'price' => 11.15,
                    'price_incl_tax' => 11.99,
                    'row_total' => 223.07,
                    'row_total_incl_tax' => 239.8, // Shouldn't this be 223.07?
                    'type' => 'product',
                    'tax_percent' => 7.5,
                    'discount_tax_compensation_amount' => 0,
                    'associated_item_code' => null,
                    'applied_taxes' => [
                        'US - 42 - 7.5' => [
                            'amount' => 16.73,
                            'percent' => 7.5,
                            'tax_rate_key' => 'US - 42 - 7.5',
                            'rates' => [
                                'US - 42 - 7.5' => [
                                    'percent' => 7.5,
                                    'code' => 'US - 42 - 7.5',
                                    'title' => 'US - 42 - 7.5',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $oneProductWithChildren = $baseQuote;
        $oneProductWithChildren['items'] = [
            [
                'code' => 'child_1_sku',
                'type' => 'product',
                'quantity' => 2,
                'unit_price' => 12.34,
                'tax_class_key' => 'DefaultProductClass',
                'parent_code' => 'parent_sku',
            ],
            [
                'code' => 'parent_sku', // Put the parent in the middle of the children to test an edge case
                'type' => 'product',
                'quantity' => 10,
                'unit_price' => 0,
                'tax_class_key' => 'DefaultProductClass',
            ],
            [
                'code' => 'child_2_sku',
                'type' => 'product',
                'quantity' => 2,
                'unit_price' => 1.99,
                'tax_class_key' => 'HigherProductClass',
                'parent_code' => 'parent_sku',
            ],
        ];
        $oneProductWithChildrenResults = [
            'subtotal' => 286.6,
            'tax_amount' => 27.27,
            'discount_tax_compensation_amount' => 0,
            'applied_taxes' => [
                [
                    'amount' => 18.51,
                    'percent' => 7.5,
                    'rates' => [
                        [
                            'code' => 'US - 42 - 7.5',
                            'title' => 'US - 42 - 7.5',
                            'percent' => 7.5,
                        ],
                    ],
                    'tax_rate_key' => 'US - 42 - 7.5',
                ],
                [
                    'amount' => 8.76,
                    'percent' => 22,
                    'rates' => [
                        [
                            'code' => 'US - 42 - 22',
                            'title' => 'US - 42 - 22',
                            'percent' => 22,
                        ],
                    ],
                    'tax_rate_key' => 'US - 42 - 22',
                ],
            ],
            'items' => [
                'child_1_sku' => [
                    'code' => 'child_1_sku',
                    'row_tax' => 18.51,
                    'price' => 12.34,
                    'price_incl_tax' => 13.27,
                    'row_total' => 246.8,
                    'row_total_incl_tax' => 265.31,
                    'type' => 'product',
                    'tax_percent' => 7.5,
                    'discount_tax_compensation_amount' => 0,
                    'associated_item_code' => null,
                    'applied_taxes' => [
                        'US - 42 - 7.5' => [
                            'amount' => 18.51,
                            'percent' => 7.5,
                            'tax_rate_key' => 'US - 42 - 7.5',
                            'rates' => [
                                'US - 42 - 7.5' => [
                                    'percent' => 7.5,
                                    'code' => 'US - 42 - 7.5',
                                    'title' => 'US - 42 - 7.5',
                                ],
                            ],
                        ],
                    ],
                ],
                'child_2_sku' => [
                    'code' => 'child_2_sku',
                    'row_tax' => 8.76,
                    'price' => 1.99,
                    'price_incl_tax' => 2.43,
                    'row_total' => 39.8,
                    'row_total_incl_tax' => 48.56,
                    'type' => 'product',
                    'tax_percent' => 22,
                    'discount_tax_compensation_amount' => 0,
                    'associated_item_code' => null,
                    'applied_taxes' => [
                        'US - 42 - 22' => [
                            'amount' => 8.76,
                            'percent' => 22,
                            'tax_rate_key' => 'US - 42 - 22',
                            'rates' => [
                                'US - 42 - 22' => [
                                    'percent' => 22,
                                    'code' => 'US - 42 - 22',
                                    'title' => 'US - 42 - 22',
                                ],
                            ],
                        ],
                    ],
                ],
                'parent_sku' => [
                    'price' => 28.66,
                    'price_incl_tax' => 31.39,
                    'row_total' => 286.6,
                    'row_total_incl_tax' => 313.87,
                    'row_tax' => 27.27,
                    'code' => 'parent_sku',
                    'type' => 'product',
                ],
            ],
        ];

        return [
            'one product' => [
                'quote_details' => $oneProduct,
                'expected_tax_details' => $oneProductResults,
            ],
            'weee_product' => [
                'quote_details' => $weeeProduct,
                'expected_tax_details' => $weeeProductResults,
            ],
            'weee_products' => [
                'quote_details' => $weeeProducts,
                'expected_tax_details' => $weeeProductsResults,
            ],
            'multi weee_products' => [
                'quote_details' => $multiWeeeProducts,
                'expected_tax_details' => $multiWeeeProductsResults,
            ],
            'one product, tax included' => [
                'quote_details' => $oneProductInclTax,
                'expected_tax_details' => $oneProductInclTaxResults,
            ],
            'one product, tax included but differs from store rate' => [
                'quote_details' => $oneProductInclTaxDiffRate,
                'expected_tax_details' => $oneProductInclTaxDiffRateResults,
            ],
            'two products' => [
                'quote_details' => $twoProducts,
                'expected_tax_details' => $twoProductsResults,
            ],
            'two products, tax included' => [
                'quote_details' => $twoProductsInclTax,
                'expected_tax_details' => $twoProductInclTaxResults,
            ],
            'one product with two children' => [
                'quote_details' => $oneProductWithChildren,
                'expected_tax_details' => $oneProductWithChildrenResults,
            ],
        ];
    }

    /**
     * Create quote details for use with multi rules tests
     *
     * @return array
     */
    protected function setupMultiRuleQuote()
    {
        $baseQuote = $this->getBaseQuoteData();

        $baseQuote['items'][] = [
            'code' => 'sku_1',
            'type' => 'product',
            'quantity' => 10,
            'unit_price' => 1.89,
            'tax_class_key' => 'MultipleRulesProductClass',
            'is_tax_included' => true,
            'discount_amount' => 5,
        ];
        $baseQuote['items'][] = [
            'code' => 'sku_2',
            'type' => 'product',
            'quantity' => 5,
            'unit_price' => 14.99,
            'tax_class_key' => 'MultipleRulesProductClass',
            'is_tax_included' => true,
            'discount_amount' => 10,
        ];
        $baseQuote['items'][] = [
            'code' => 'sku_3',
            'type' => 'product',
            'quantity' => 1,
            'unit_price' => 99.99,
            'tax_class_key' => 'MultipleRulesProductClass',
            'is_tax_included' => false,
            'discount_amount' => 5,
        ];

        return $baseQuote;
    }

    /**
     * Create the base results for the multi rules test
     *
     * @return array
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    protected function getBaseQuoteResult()
    {
        $result = [
            'subtotal' => 183.75,
            'tax_amount' => 42.88,
            'discount_tax_compensation_amount' => 3.08,
            'applied_taxes' => [
                [
                    'amount' => 22.1,
                    'percent' => 13.25,
                    'rates' => [
                        [
                            'code' => 'US - 42 - 8.25',
                            'title' => 'US - 42 - 8.25',
                            'percent' => 8.25,
                        ],
                        [
                            'code' => 'US - 42 - 5 - 55555',
                            'title' => 'US - 42 - 5 - 55555',
                            'percent' => 5,
                        ],
                    ],
                    'tax_rate_key' => 'US - 42 - 8.25US - 42 - 5 - 55555',
                ],
                [
                    'amount' => 20.78,
                    'percent' => 12.4575,
                    'rates' => [
                        [
                            'code' => 'US - 42 - 11 - 55555',
                            'title' => 'US - 42 - 11 - 55555',
                            'percent' => 11,
                        ],
                    ],
                    'tax_rate_key' => 'US - 42 - 11 - 55555',
                ],
            ],
            'items' => [
                'sku_1' => [
                    'code' => 'sku_1',
                    'row_tax' => 3.31,
                    'price' => 1.69,
                    'price_incl_tax' => 2.12,
                    'row_total' => 16.86,
                    'row_total_incl_tax' => 21.2,
                    'type' => 'product',
                    'tax_percent' => 25.7075,
                    'discount_tax_compensation_amount' => 1.03,
                    'associated_item_code' => null,
                    'applied_taxes' => [
                        'US - 42 - 8.25US - 42 - 5 - 55555' => [
                            'amount' => 1.71,
                            'percent' => 13.25,
                            'tax_rate_key' => 'US - 42 - 8.25US - 42 - 5 - 55555',
                            'rates' => [
                                'US - 42 - 8.25' => [
                                    'percent' => 8.25,
                                    'code' => 'US - 42 - 8.25',
                                    'title' => 'US - 42 - 8.25',
                                ],
                                'US - 42 - 5 - 55555' => [
                                    'percent' => 5,
                                    'code' => 'US - 42 - 5 - 55555',
                                    'title' => 'US - 42 - 5 - 55555',
                                ],
                            ],
                        ],
                        'US - 42 - 11 - 55555' => [
                            'amount' => 1.6,
                            'percent' => 12.4575,
                            'tax_rate_key' => 'US - 42 - 11 - 55555',
                            'rates' => [
                                'US - 42 - 11 - 55555' => [
                                    'percent' => 11,
                                    'code' => 'US - 42 - 11 - 55555',
                                    'title' => 'US - 42 - 11 - 55555',
                                ],
                            ],
                        ],
                    ],
                ],
                'sku_2' => [
                    'code' => 'sku_2',
                    'row_tax' => 15.15,
                    'price' => 13.38,
                    'price_incl_tax' => 16.82,
                    'row_total' => 66.9,
                    'row_total_incl_tax' => 84.1,
                    'type' => 'product',
                    'tax_percent' => 25.7075,
                    'discount_tax_compensation_amount' => 2.05,
                    'associated_item_code' => null,
                    'applied_taxes' => [
                        'US - 42 - 8.25US - 42 - 5 - 55555' => [
                            'amount' => 7.8,
                            'percent' => 13.25,
                            'tax_rate_key' => 'US - 42 - 8.25US - 42 - 5 - 55555',
                            'rates' => [
                                'US - 42 - 8.25' => [
                                    'percent' => 8.25,
                                    'code' => 'US - 42 - 8.25',
                                    'title' => 'US - 42 - 8.25',
                                ],
                                'US - 42 - 5 - 55555' => [
                                    'percent' => 5,
                                    'code' => 'US - 42 - 5 - 55555',
                                    'title' => 'US - 42 - 5 - 55555',
                                ],
                            ],
                        ],
                        'US - 42 - 11 - 55555' => [
                            'amount' => 7.35,
                            'percent' => 12.4575,
                            'tax_rate_key' => 'US - 42 - 11 - 55555',
                            'rates' => [
                                'US - 42 - 11 - 55555' => [
                                    'percent' => 11,
                                    'code' => 'US - 42 - 11 - 55555',
                                    'title' => 'US - 42 - 11 - 55555',
                                ],
                            ],
                        ],
                    ],
                ],
                'sku_3' => [
                    'code' => 'sku_3',
                    'row_tax' => 24.42,
                    'price' => 99.99,
                    'price_incl_tax' => 125.7,
                    'row_total' => 99.99,
                    'row_total_incl_tax' => 125.7,
                    'type' => 'product',
                    'tax_percent' => 25.7075,
                    'discount_tax_compensation_amount' => 0,
                    'associated_item_code' => null,
                    'applied_taxes' => [
                        'US - 42 - 8.25US - 42 - 5 - 55555' => [
                            'amount' => 12.59,
                            'percent' => 13.25,
                            'tax_rate_key' => 'US - 42 - 8.25US - 42 - 5 - 55555',
                            'rates' => [
                                'US - 42 - 8.25' => [
                                    'percent' => 8.25,
                                    'code' => 'US - 42 - 8.25',
                                    'title' => 'US - 42 - 8.25',
                                ],
                                'US - 42 - 5 - 55555' => [
                                    'percent' => 5,
                                    'code' => 'US - 42 - 5 - 55555',
                                    'title' => 'US - 42 - 5 - 55555',
                                ],
                            ],
                        ],
                        'US - 42 - 11 - 55555' => [
                            'amount' => 11.83,
                            'percent' => 12.4575,
                            'tax_rate_key' => 'US - 42 - 11 - 55555',
                            'rates' => [
                                'US - 42 - 11 - 55555' => [
                                    'percent' => 11,
                                    'code' => 'US - 42 - 11 - 55555',
                                    'title' => 'US - 42 - 11 - 55555',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        return $result;
    }

    /**
     * @magentoDbIsolation enabled
     * @dataProvider multiRulesRowBasedDataProvider
     * @magentoConfigFixture default_store tax/calculation/algorithm ROW_BASE_CALCULATION
     */
    public function testMultiRulesRowBased($quoteDetailsData, $expectedTaxDetails)
    {
        $quoteDetailsData = $this->performTaxClassSubstitution($quoteDetailsData);
        $quoteDetails = $this->quoteDetailsFactory->create();
        $this->dataObjectHelper->populateWithArray(
            $quoteDetails,
            $quoteDetailsData,
            \Magento\Tax\Api\Data\QuoteDetailsInterface::class
        );

        $taxDetails = $this->taxCalculationService->calculateTax($quoteDetails);

        $this->assertEqualsWithDelta($expectedTaxDetails, $this->convertObjectToArray($taxDetails), self::EPSILON);
    }

    /**
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function multiRulesRowBasedDataProvider()
    {
        $quoteDetails = $this->setupMultiRuleQuote();

        $results = $this->getBaseQuoteResult();

        return [
            'multi rules, multi rows' => [
                'quote_details' => $quoteDetails,
                'expected_tax_details' => $results,
            ],
        ];
    }

    /**
     * @magentoDbIsolation enabled
     * @dataProvider multiRulesTotalBasedDataProvider
     * @magentoConfigFixture default_store tax/calculation/algorithm TOTAL_BASE_CALCULATION
     */
    public function testMultiRulesTotalBased($quoteDetailsData, $expectedTaxDetails)
    {
        $quoteDetailsData = $this->performTaxClassSubstitution($quoteDetailsData);
        $quoteDetails = $this->quoteDetailsFactory->create();
        $this->dataObjectHelper->populateWithArray(
            $quoteDetails,
            $quoteDetailsData,
            \Magento\Tax\Api\Data\QuoteDetailsInterface::class
        );

        $taxDetails = $this->taxCalculationService->calculateTax($quoteDetails);

        $this->assertEqualsWithDelta($expectedTaxDetails, $this->convertObjectToArray($taxDetails), self::EPSILON);
    }

    /**
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function multiRulesTotalBasedDataProvider()
    {
        $quoteDetails = $this->setupMultiRuleQuote();

        $results = $this->getBaseQuoteResult();

        //Differences from the row base result
        $results['subtotal'] = 183.76;
        $results['tax_amount'] = 42.89;
        $results['discount_tax_compensation_amount'] = 3.06;
        $results['applied_taxes'][0]['amount'] = 22.11;
        $results['items']['sku_2']['row_tax'] = 15.16;
        $results['items']['sku_2']['row_total'] = 66.91;
        $results['items']['sku_2']['discount_tax_compensation_amount'] = 2.03;
        $results['items']['sku_2']['applied_taxes']['US - 42 - 8.25US - 42 - 5 - 55555']['amount'] = 7.81;

        return [
            'multi rules, multi rows' => [
                'quote_details' => $quoteDetails,
                'expected_tax_details' => $results,
            ],
        ];
    }

    /**
     * @magentoDbIsolation enabled
     * @dataProvider multiRulesUnitBasedDataProvider
     * @magentoConfigFixture default_store tax/calculation/algorithm UNIT_BASE_CALCULATION
     */
    public function testMultiRulesUnitBased($quoteDetailsData, $expectedTaxDetails)
    {
        $quoteDetailsData = $this->performTaxClassSubstitution($quoteDetailsData);
        $quoteDetails = $this->quoteDetailsFactory->create();
        $this->dataObjectHelper->populateWithArray(
            $quoteDetails,
            $quoteDetailsData,
            \Magento\Tax\Api\Data\QuoteDetailsInterface::class
        );

        $taxDetails = $this->taxCalculationService->calculateTax($quoteDetails);

        $this->assertEqualsWithDelta($expectedTaxDetails, $this->convertObjectToArray($taxDetails), self::EPSILON);
    }

    /**
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function multiRulesUnitBasedDataProvider()
    {
        $quoteDetails = $this->setupMultiRuleQuote();

        $results = $this->getBaseQuoteResult();

        //Differences from the row base result
        $results['subtotal'] = 183.79;
        $results['tax_amount'] = 42.87;
        $results['discount_tax_compensation_amount'] = 3.05;
        $results['applied_taxes'][1]['amount'] = 20.77;
        $results['items']['sku_1']['row_tax'] = 3.3;
        $results['items']['sku_1']['row_total'] = 16.9;
        $results['items']['sku_1']['discount_tax_compensation_amount'] = 1;
        $results['items']['sku_1']['applied_taxes']['US - 42 - 8.25US - 42 - 5 - 55555']['amount'] = 1.7;
        $results['items']['sku_2']['applied_taxes']['US - 42 - 8.25US - 42 - 5 - 55555']['amount'] = 7.81;
        $results['items']['sku_2']['applied_taxes']['US - 42 - 11 - 55555']['amount'] = 7.34;

        return [
            'multi rules, multi rows' => [
                'quote_details' => $quoteDetails,
                'expected_tax_details' => $results,
            ],
        ];
    }

    /**
     * Substitutes an ID for the name of a tax class in a tax class ID field.
     *
     * @param array $data
     * @return array
     */
    private function performTaxClassSubstitution($data)
    {
        array_walk_recursive(
            $data,
            function (&$value, $key) {
                if (($key === 'tax_class_key' || $key === 'customer_tax_class_key')
                    && is_string($value)
                ) {
                    $value = [
                        Key::KEY_TYPE => TaxClassKeyInterface::TYPE_ID,
                        Key::KEY_VALUE => $this->taxClassIds[$value],
                    ];
                }
            }
        );

        return $data;
    }

    /**
     * Helper function that sets up some default rules
     */
    private function setUpDefaultRules()
    {
        $this->taxClassIds = $this->taxRuleFixtureFactory->createTaxClasses([
            ['name' => 'DefaultCustomerClass', 'type' => ClassModel::TAX_CLASS_TYPE_CUSTOMER],
            ['name' => 'DefaultProductClass', 'type' => ClassModel::TAX_CLASS_TYPE_PRODUCT],
            ['name' => 'HigherProductClass', 'type' => ClassModel::TAX_CLASS_TYPE_PRODUCT],
            ['name' => 'MultipleRulesProductClass', 'type' => ClassModel::TAX_CLASS_TYPE_PRODUCT],
            ['name' => 'WeeeProductClass', 'type' => ClassModel::TAX_CLASS_TYPE_PRODUCT],
        ]);

        $this->taxRates = $this->taxRuleFixtureFactory->createTaxRates([
            ['percentage' => 7.5, 'country' => 'US', 'region' => 42],
            ['percentage' => 7.5, 'country' => 'US', 'region' => 12], // Default store rate
        ]);

        $weeeTaxRates = $this->taxRuleFixtureFactory->createTaxRates([
            ['percentage' => 8.25, 'country' => 'US', 'region' => 12] // Default store rate
        ]);

        $multiTaxRates1 = $this->taxRuleFixtureFactory->createTaxRates([
            ['percentage' => 8.25, 'country' => 'US', 'region' => 42],
            ['percentage' => 12, 'country' => 'US', 'region' => 12], // Default store rate
        ]);

        $multiTaxRatesSamePriority = $this->taxRuleFixtureFactory->createTaxRates([
            ['percentage' => 5, 'country' => 'US', 'region' => 42, 'postcode' => '55555'],
        ]);

        $multiTaxRatesDifferentPriority = $this->taxRuleFixtureFactory->createTaxRates([
            ['percentage' => 11, 'country' => 'US', 'region' => 42, 'postcode' => '55555'],
        ]);

        $higherRates = $this->taxRuleFixtureFactory->createTaxRates([
            ['percentage' => 22, 'country' => 'US', 'region' => 42],
            ['percentage' => 10, 'country' => 'US', 'region' => 12], // Default store rate
        ]);

        $this->taxRules = $this->taxRuleFixtureFactory->createTaxRules([
            [
                'code' => 'Default Rule',
                'customer_tax_class_ids' => [$this->taxClassIds['DefaultCustomerClass'], 3],
                'product_tax_class_ids' => [$this->taxClassIds['DefaultProductClass']],
                'tax_rate_ids' => array_values($this->taxRates),
                'sort_order' => 0,
                'priority' => 0,
            ],
            [
                'code' => 'Weee Rule',
                'customer_tax_class_ids' => [$this->taxClassIds['DefaultCustomerClass'], 3],
                'product_tax_class_ids' => [$this->taxClassIds['WeeeProductClass']],
                'tax_rate_ids' => array_values($weeeTaxRates),
                'sort_order' => 0,
                'priority' => 0,
            ],
            [
                'code' => 'Higher Rate Rule',
                'customer_tax_class_ids' => [$this->taxClassIds['DefaultCustomerClass'], 3],
                'product_tax_class_ids' => [$this->taxClassIds['HigherProductClass']],
                'tax_rate_ids' => array_values($higherRates),
                'sort_order' => 0,
                'priority' => 0,
            ],
            [
                'code' => 'MultiRule-1',
                'customer_tax_class_ids' => [$this->taxClassIds['DefaultCustomerClass'], 3],
                'product_tax_class_ids' => [
                    $this->taxClassIds['MultipleRulesProductClass'],
                    $this->taxClassIds['WeeeProductClass']
                ],
                'tax_rate_ids' => array_values($multiTaxRates1),
                'sort_order' => 0,
                'priority' => 0,
            ],
            [
                'code' => 'MultiRule-2',
                'customer_tax_class_ids' => [$this->taxClassIds['DefaultCustomerClass'], 3],
                'product_tax_class_ids' => [$this->taxClassIds['MultipleRulesProductClass']],
                'tax_rate_ids' => array_values($multiTaxRatesSamePriority),
                'sort_order' => 0,
                'priority' => 0,
            ],
            [
                'code' => 'MultiRule-3',
                'customer_tax_class_ids' => [$this->taxClassIds['DefaultCustomerClass'], 3],
                'product_tax_class_ids' => [$this->taxClassIds['MultipleRulesProductClass']],
                'tax_rate_ids' => array_values($multiTaxRatesDifferentPriority),
                'sort_order' => 0,
                'priority' => 1,
            ],
        ]);

        // For cleanup
        $this->taxRates = array_merge($this->taxRates, $weeeTaxRates);
        $this->taxRates = array_merge($this->taxRates, $higherRates);
        $this->taxRates = array_merge($this->taxRates, $multiTaxRates1);
        $this->taxRates = array_merge($this->taxRates, $multiTaxRatesSamePriority);
        $this->taxRates = array_merge($this->taxRates, $multiTaxRatesDifferentPriority);
    }

    /**
     * Helper function that tears down some default rules
     */
    private function tearDownDefaultRules()
    {
        $this->taxRuleFixtureFactory->deleteTaxRules(array_values($this->taxRules));
        $this->taxRuleFixtureFactory->deleteTaxRates(array_values($this->taxRates));
        $this->taxRuleFixtureFactory->deleteTaxClasses(array_values($this->taxClassIds));
    }

    /**
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     *
     * @return array
     */
    private function getBaseQuoteData()
    {
        $baseQuote = [
            'billing_address' => [
                'postcode' => '55555',
                'country_id' => 'US',
                'region' => ['region_id' => 42],
            ],
            'shipping_address' => [
                'postcode' => '55555',
                'country_id' => 'US',
                'region' => ['region_id' => 42],
            ],
            'items' => [],
            'customer_tax_class_key' => 'DefaultCustomerClass',
        ];
        return $baseQuote;
    }

    /**
     * Convert given object to array.
     *
     * This utility function is used to simplify expected result verification.
     *
     * @param \Magento\Framework\DataObject $object
     * @return array
     */
    private function convertObjectToArray($object)
    {
        if ($object instanceof \Magento\Framework\DataObject) {
            $data = $object->getData();
        } elseif (is_object($object)) {
            $data = (array)$object;
        } else {
            throw new \InvalidArgumentException("Provided argument is not an object.");
        }
        foreach ($data as $key => $value) {
            if (is_object($value)) {
                $data[$key] = $this->convertObjectToArray($value);
            } elseif (is_array($value)) {
                foreach ($value as $nestedKey => $nestedValue) {
                    if (is_object($nestedValue)) {
                        $value[$nestedKey] = $this->convertObjectToArray($nestedValue);
                    }
                }
                $data[$key] = $value;
            }
        }
        return $data;
    }
}
