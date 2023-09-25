<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\GraphQl\Tax;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\TestFramework\ObjectManager;
use Magento\TestFramework\TestCase\GraphQlAbstract;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Tax\Model\Config;

/**
 * @magentoAppIsolation enabled
 */
class ProductViewTest extends GraphQlAbstract
{
    /**
     * @var ObjectManager
     */
    private $objectManager;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /** @var \Magento\Tax\Model\Calculation\Rate[] */
    private $fixtureTaxRates;

    /** @var \Magento\Tax\Model\Calculation\Rule[] */
    private $fixtureTaxRules;

    /** @var string */
    private $defaultRegionSystemSetting;

    /** @var string */
    private $defaultPriceDisplayType;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    protected function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $this->productRepository = $this->objectManager->get(ProductRepositoryInterface::class);
        $this->storeManager = $this->objectManager->get(StoreManagerInterface::class);

        /** @var \Magento\Config\Model\ResourceModel\Config $config */
        $config = $this->objectManager->get(\Magento\Config\Model\ResourceModel\Config::class);

        /** @var ScopeConfigInterface $scopeConfig */
        $scopeConfig = $this->objectManager->get(ScopeConfigInterface::class);

        $this->defaultRegionSystemSetting = $scopeConfig->getValue(
            Config::CONFIG_XML_PATH_DEFAULT_REGION
        );

        $this->defaultPriceDisplayType = $scopeConfig->getValue(
            Config::CONFIG_XML_PATH_PRICE_DISPLAY_TYPE
        );

        //default state tax calculation AL
        $config->saveConfig(
            Config::CONFIG_XML_PATH_DEFAULT_REGION,
            1
        );

        $config->saveConfig(
            Config::CONFIG_XML_PATH_PRICE_DISPLAY_TYPE,
            3
        );
        $this->getFixtureTaxRates();
        $this->getFixtureTaxRules();

        /** @var \Magento\Framework\App\Config\ReinitableConfigInterface $config */
        $config = $this->objectManager->get(\Magento\Framework\App\Config\ReinitableConfigInterface::class);
        $config->reinit();
        /** @var ScopeConfigInterface $scopeConfig */
        $scopeConfig = $this->objectManager->get(ScopeConfigInterface::class);
        $scopeConfig->clean();
    }

    protected function tearDown(): void
    {
        /** @var \Magento\Config\Model\ResourceModel\Config $config */
        $config = $this->objectManager->get(\Magento\Config\Model\ResourceModel\Config::class);

        //default state tax calculation AL
        $config->saveConfig(
            Config::CONFIG_XML_PATH_DEFAULT_REGION,
            $this->defaultRegionSystemSetting
        );

        $config->saveConfig(
            Config::CONFIG_XML_PATH_PRICE_DISPLAY_TYPE,
            $this->defaultPriceDisplayType
        );
        $taxRules = $this->getFixtureTaxRules();
        if (count($taxRules)) {
            $taxRates = $this->getFixtureTaxRates();
            foreach ($taxRules as $taxRule) {
                $taxRule->delete();
            }
            foreach ($taxRates as $taxRate) {
                $taxRate->delete();
            }
        }

        /** @var \Magento\Framework\App\Config\ReinitableConfigInterface $config */
        $config = $this->objectManager->get(\Magento\Framework\App\Config\ReinitableConfigInterface::class);
        $config->reinit();

        /** @var ScopeConfigInterface $scopeConfig */
        $scopeConfig = $this->objectManager->get(ScopeConfigInterface::class);
        $scopeConfig->clean();
    }

    /**
     * @magentoApiDataFixture Magento/Customer/_files/customer.php
     * @magentoApiDataFixture Magento/Customer/_files/customer_primary_addresses.php
     * @magentoApiDataFixture Magento/Catalog/_files/product_simple_with_all_fields.php
     * @magentoApiDataFixture Magento/Tax/_files/tax_rule_region_1_al.php
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function testQueryAllFieldsSimpleProduct()
    {
        $productSku = 'simple';

        $product = $this->productRepository->get($productSku, null, null, true);
        // set product to taxable goods
        $product->setData('tax_class_id', 2)->save();

        $query = <<<QUERY
{
    products(filter: {sku: {eq: "{$productSku}"}})
    {
        items {
            id
            name
            price {
              minimalPrice {
                amount {
                  value
                  currency
                }
                adjustments {
                  amount {
                    value
                    currency
                  }
                  code
                  description
                }
              }
              maximalPrice {
                amount {
                  value
                  currency
                }
                adjustments {
                  amount {
                    value
                    currency
                  }
                  code
                  description
                }
              }
              regularPrice {
                amount {
                  value
                  currency
                }
                adjustments {
                  amount {
                    value
                    currency
                  }
                  code
                  description
                }
              }
            }
            sku
            type_id
            ... on PhysicalProductInterface {
                weight
            }
        }
    }
}
QUERY;

        $response = $this->graphQlQuery($query);

        /** @var \Magento\Catalog\Model\Product $product */
        $product = $this->productRepository->get($productSku, false, null, true);
        $this->assertArrayHasKey('products', $response);
        $this->assertArrayHasKey('items', $response['products']);
        $this->assertCount(1, $response['products']['items']);
        $this->assertArrayHasKey(0, $response['products']['items']);
        $this->assertBaseFields($product, $response['products']['items'][0]);
    }

    /**
     * Get tax rates created in Magento\Tax\_files\tax_rule_region_1_al.php
     *
     * @return \Magento\Tax\Model\Calculation\Rate[]
     */
    private function getFixtureTaxRates()
    {
        if ($this->fixtureTaxRates === null) {
            $this->fixtureTaxRates = [];
            if ($this->getFixtureTaxRules()) {
                $taxRateIds = (array)$this->getFixtureTaxRules()[0]->getRates();
                foreach ($taxRateIds as $taxRateId) {
                    /** @var \Magento\Tax\Model\Calculation\Rate $taxRate */
                    $taxRate = Bootstrap::getObjectManager()->create(\Magento\Tax\Model\Calculation\Rate::class);
                    $this->fixtureTaxRates[] = $taxRate->load($taxRateId);
                }
            }
        }
        return $this->fixtureTaxRates;
    }

    /**
     * Get tax rule created in Magento\Tax\_files\tax_rule_region_1_al.php
     *
     * @return \Magento\Tax\Model\Calculation\Rule[]
     */
    private function getFixtureTaxRules()
    {
        if ($this->fixtureTaxRules === null) {
            $this->fixtureTaxRules = [];
            $taxRuleCodes = ['AL Test Rule'];
            foreach ($taxRuleCodes as $taxRuleCode) {
                /** @var \Magento\Tax\Model\Calculation\Rule $taxRule */
                $taxRule = Bootstrap::getObjectManager()->create(\Magento\Tax\Model\Calculation\Rule::class);
                $taxRule->load($taxRuleCode, 'code');
                if ($taxRule->getId()) {
                    $this->fixtureTaxRules[] = $taxRule;
                }
            }
        }
        return $this->fixtureTaxRules;
    }

    /**
     * @param ProductInterface $product
     * @param array $actualResponse
     */
    private function assertBaseFields($product, $actualResponse)
    {
        $pricesTypes = [
            'minimalPrice',
            'regularPrice',
            'maximalPrice',
        ];
        foreach ($pricesTypes as $priceType) {
            if (isset($actualResponse['price'][$priceType]['amount']['value'])) {
                $actualResponse['price'][$priceType]['amount']['value'] =
                    round($actualResponse['price'][$priceType]['amount']['value'], 4);
            }

            if (isset($actualResponse['price'][$priceType]['adjustments'][0]['amount']['value'])) {
                $actualResponse['price'][$priceType]['adjustments'][0]['amount']['value'] =
                    round($actualResponse['price'][$priceType]['adjustments'][0]['amount']['value'], 4);
            }
        }
        // product_object_field_name, expected_value
        $assertionMap = [
            ['response_field' => 'id', 'expected_value' => $product->getId()],
            ['response_field' => 'name', 'expected_value' => $product->getName()],
            ['response_field' => 'price', 'expected_value' =>
                [
                    'minimalPrice' => [
                        'amount' => [
                            'value' => 4.1065,
                            'currency' => 'USD'
                        ],
                        'adjustments' => [
                            0 =>
                                [
                                    'amount' =>
                                        [
                                            'value' => 0.2865,
                                            'currency' => 'USD',
                                        ],
                                        'code' => 'TAX',
                                        'description' => 'INCLUDED',
                                ],
                        ]
                    ],
                    'regularPrice' => [
                        'amount' => [
                            'value' => 10.7500,
                            'currency' => 'USD'
                        ],
                        'adjustments' => [
                            0 =>
                                [
                                    'amount' =>
                                        [
                                            'value' => 0.7500,
                                            'currency' => 'USD',
                                        ],
                                        'code' => 'TAX',
                                        'description' => 'INCLUDED',
                                ],
                        ]
                    ],
                    'maximalPrice' => [
                        'amount' => [
                            'value' => 4.1065,
                            'currency' => 'USD'
                        ],
                        'adjustments' => [
                            0 =>
                                [
                                    'amount' =>
                                        [
                                            'value' => 0.2865,
                                            'currency' => 'USD',
                                        ],
                                        'code' => 'TAX',
                                        'description' => 'INCLUDED',
                                ],
                        ]
                    ],
                ]
            ],
            ['response_field' => 'sku', 'expected_value' => $product->getSku()],
            ['response_field' => 'type_id', 'expected_value' => $product->getTypeId()],
            ['response_field' => 'weight', 'expected_value' => $product->getWeight()],
        ];

        $this->assertResponseFields($actualResponse, $assertionMap);
    }
}
