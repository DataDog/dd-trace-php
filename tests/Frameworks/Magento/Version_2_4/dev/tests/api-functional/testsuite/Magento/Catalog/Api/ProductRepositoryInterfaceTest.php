<?php

/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Catalog\Api;

use Magento\Authorization\Model\Role;
use Magento\Authorization\Model\RoleFactory;
use Magento\Authorization\Model\Rules;
use Magento\UrlRewrite\Model\ResourceModel\UrlRewriteCollectionFactory;
use Magento\Authorization\Model\RulesFactory;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\ResourceModel\Product\Gallery;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\Downloadable\Api\DomainManagerInterface;
use Magento\Downloadable\Model\Link;
use Magento\Framework\Api\ExtensibleDataInterface;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Webapi\Exception as HTTPExceptionCodes;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Integration\Api\AdminTokenServiceInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreWebsiteRelationInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreRepository;
use Magento\Store\Model\Website;
use Magento\Store\Model\WebsiteRepository;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\WebapiAbstract;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;

/**
 * Test for \Magento\Catalog\Api\ProductRepositoryInterface
 *
 * @magentoAppIsolation enabled
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class ProductRepositoryInterfaceTest extends WebapiAbstract
{
    const SERVICE_NAME = 'catalogProductRepositoryV1';
    const SERVICE_VERSION = 'V1';
    const RESOURCE_PATH = '/V1/products';

    const KEY_TIER_PRICES = 'tier_prices';
    const KEY_SPECIAL_PRICE = 'special_price';
    const KEY_CATEGORY_LINKS = 'category_links';

    /**
     * @var array
     */
    private $productData = [
        [
            ProductInterface::SKU => 'simple',
            ProductInterface::NAME => 'Simple Related Product',
            ProductInterface::TYPE_ID => 'simple',
            ProductInterface::PRICE => 10,
        ],
        [
            ProductInterface::SKU => 'simple_with_cross',
            ProductInterface::NAME => 'Simple Product With Related Product',
            ProductInterface::TYPE_ID => 'simple',
            ProductInterface::PRICE => 10,
        ],
    ];

    /**
     * @var RoleFactory
     */
    private $roleFactory;

    /**
     * @var RulesFactory
     */
    private $rulesFactory;

    /**
     * @var AdminTokenServiceInterface
     */
    private $adminTokens;

    /**
     * @var array
     */
    private $fixtureProducts = [];

    /**
     * @var UrlRewriteCollectionFactory
     */
    private $urlRewriteCollectionFactory;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        parent::setUp();

        $objectManager = Bootstrap::getObjectManager();
        $this->roleFactory = $objectManager->get(RoleFactory::class);
        $this->rulesFactory = $objectManager->get(RulesFactory::class);
        $this->adminTokens = $objectManager->get(AdminTokenServiceInterface::class);
        $this->urlRewriteCollectionFactory = $objectManager->get(UrlRewriteCollectionFactory::class);
        /** @var DomainManagerInterface $domainManager */
        $domainManager = $objectManager->get(DomainManagerInterface::class);
        $domainManager->addDomains(['example.com']);
    }

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        $this->deleteFixtureProducts();
        parent::tearDown();

        $objectManager = Bootstrap::getObjectManager();
        /** @var DomainManagerInterface $domainManager */
        $domainManager = $objectManager->get(DomainManagerInterface::class);
        $domainManager->removeDomains(['example.com']);
    }

    /**
     * Test get() method
     *
     * @magentoApiDataFixture Magento/Catalog/_files/products_related.php
     */
    public function testGet()
    {
        $productData = $this->productData[0];
        $response = $this->getProduct($productData[ProductInterface::SKU]);
        foreach ([ProductInterface::SKU, ProductInterface::NAME, ProductInterface::PRICE] as $key) {
            $this->assertEquals($productData[$key], $response[$key]);
        }
        $this->assertEquals([1], $response[ProductInterface::EXTENSION_ATTRIBUTES_KEY]["website_ids"]);
    }

    /**
     * Get product
     *
     * @param string $sku
     * @param string|null $storeCode
     * @return array|bool|float|int|string
     */
    protected function getProduct($sku, $storeCode = null)
    {
        $serviceInfo = [
            'rest' => [
                'resourcePath' => self::RESOURCE_PATH . '/' . $sku,
                'httpMethod' => Request::HTTP_METHOD_GET,
            ],
            'soap' => [
                'service' => self::SERVICE_NAME,
                'serviceVersion' => self::SERVICE_VERSION,
                'operation' => self::SERVICE_NAME . 'Get',
            ],
        ];
        $response = $this->_webApiCall($serviceInfo, ['sku' => $sku], null, $storeCode);

        return $response;
    }

    /**
     * Test get() method with invalid sku
     */
    public function testGetNoSuchEntityException()
    {
        $invalidSku = '(nonExistingSku)';
        $serviceInfo = [
            'rest' => [
                'resourcePath' => self::RESOURCE_PATH . '/' . $invalidSku,
                'httpMethod' => Request::HTTP_METHOD_GET,
            ],
            'soap' => [
                'service' => self::SERVICE_NAME,
                'serviceVersion' => self::SERVICE_VERSION,
                'operation' => self::SERVICE_NAME . 'Get',
            ],
        ];

        $expectedMessage = "The product that was requested doesn't exist. Verify the product and try again.";

        try {
            $this->_webApiCall($serviceInfo, ['sku' => $invalidSku]);
            $this->fail("Expected throwing exception");
        } catch (\SoapFault $e) {
            $this->assertStringContainsString(
                $expectedMessage,
                $e->getMessage(),
                "SoapFault does not contain expected message."
            );
        } catch (\Exception $e) {
            $errorObj = $this->processRestExceptionResult($e);
            $this->assertEquals($expectedMessage, $errorObj['message']);
            $this->assertEquals(HTTPExceptionCodes::HTTP_NOT_FOUND, $e->getCode());
        }
    }

    /**
     * Product creation provider
     *
     * @return array
     */
    public function productCreationProvider()
    {
        $productBuilder = function ($data) {
            return array_replace_recursive(
                $this->getSimpleProductData(),
                $data
            );
        };

        return [
            [$productBuilder([ProductInterface::TYPE_ID => 'simple', ProductInterface::SKU => 'psku-test-1'])],
            [$productBuilder([ProductInterface::TYPE_ID => 'virtual', ProductInterface::SKU => 'psku-test-2'])],
        ];
    }

    /**
     * Load website by website code
     *
     * @param $websiteCode
     * @return Website
     */
    private function loadWebsiteByCode($websiteCode)
    {
        $websiteRepository = Bootstrap::getObjectManager()->get(WebsiteRepository::class);
        try {
            $website = $websiteRepository->get($websiteCode);
        } catch (NoSuchEntityException $e) {
            $website = null;
            $this->fail("Couldn`t load website: {$websiteCode}");
        }

        return $website;
    }

    /**
     * Test removing association between product and website 1
     *
     * @magentoApiDataFixture Magento/Catalog/_files/product_with_two_websites.php
     */
    public function testUpdateWithDeleteWebsites()
    {
        $productBuilder[ProductInterface::SKU] = 'unique-simple-azaza';
        /** @var Website $website */
        $website = $this->loadWebsiteByCode('second_website');

        $websitesData = [
            'website_ids' => [
                $website->getId(),
            ],
        ];
        $productBuilder[ProductInterface::EXTENSION_ATTRIBUTES_KEY] = $websitesData;
        $response = $this->updateProduct($productBuilder);
        $this->assertEquals(
            $response[ProductInterface::EXTENSION_ATTRIBUTES_KEY]["website_ids"],
            $websitesData["website_ids"]
        );
    }

    /**
     * Test removing association between product and website 1 then check url rewrite removed
     * Assign website back and check rewrite generated
     *
     * @magentoApiDataFixture Magento/Catalog/_files/product_two_websites.php
     */
    public function testUpdateRewriteWithChangeWebsites()
    {
        /** @var Website $website */
        $website = $this->loadWebsiteByCode('test');

        $productBuilder[ProductInterface::SKU] = 'simple-on-two-websites';
        $productBuilder[ProductInterface::EXTENSION_ATTRIBUTES_KEY] = [
            'website_ids' => [
                $website->getId(),
            ],
        ];
        $objectManager = Bootstrap::getObjectManager();
        /** @var StoreWebsiteRelationInterface $storeWebsiteRelation */
        $storeWebsiteRelation = $objectManager->get(StoreWebsiteRelationInterface::class);
        /** @var ProductRepositoryInterface $productRepository */
        $productRepository = $objectManager->get(ProductRepositoryInterface::class);

        $baseWebsite = $this->loadWebsiteByCode('base');
        $storeIds = $storeWebsiteRelation->getStoreByWebsiteId($baseWebsite->getId());
        $product = $productRepository->get($productBuilder[ProductInterface::SKU], false, reset($storeIds));
        $this->assertStringContainsString(
            $product->getUrlKey() . '.html',
            $product->getProductUrl()
        );

        $this->updateProduct($productBuilder);

        $product->setRequestPath('');
        $this->assertStringNotContainsString(
            $product->getUrlKey() . '.html',
            $product->getProductUrl()
        );
        $productBuilder[ProductInterface::EXTENSION_ATTRIBUTES_KEY] = [
            'website_ids' => [
                $website->getId(),
                $baseWebsite->getId(),
            ],
        ];

        $this->updateProduct($productBuilder);
        $product->setRequestPath('');
        $this->assertStringContainsString(
            $product->getUrlKey() . '.html',
            $product->getProductUrl()
        );
    }

    /**
     * Test removing all website associations
     *
     * @magentoApiDataFixture Magento/Catalog/_files/product_with_two_websites.php
     */
    public function testDeleteAllWebsiteAssociations()
    {
        $productBuilder[ProductInterface::SKU] = 'unique-simple-azaza';

        $websitesData = [
            'website_ids' => [],
        ];
        $productBuilder[ProductInterface::EXTENSION_ATTRIBUTES_KEY] = $websitesData;
        $response = $this->updateProduct($productBuilder);
        $this->assertEquals(
            $response[ProductInterface::EXTENSION_ATTRIBUTES_KEY]["website_ids"],
            $websitesData["website_ids"]
        );
    }

    /**
     * Test create() method with multiple websites
     *
     * @magentoApiDataFixture Magento/Catalog/_files/second_website.php
     */
    public function testCreateWithMultipleWebsites()
    {
        $productBuilder = $this->getSimpleProductData();
        $productBuilder[ProductInterface::SKU] = 'test-test-sku';
        $productBuilder[ProductInterface::TYPE_ID] = 'simple';
        /** @var Website $website */
        $website = $this->loadWebsiteByCode('test_website');

        $websitesData = [
            'website_ids' => [
                1,
                (int)$website->getId(),
            ],
        ];
        $productBuilder[ProductInterface::EXTENSION_ATTRIBUTES_KEY] = $websitesData;
        $response = $this->saveProduct($productBuilder);
        $this->assertEquals(
            $response[ProductInterface::EXTENSION_ATTRIBUTES_KEY]["website_ids"],
            $websitesData["website_ids"]
        );
        $this->deleteProduct($productBuilder[ProductInterface::SKU]);
    }

    /**
     * Add product associated with website that is not associated with default store
     *
     * @magentoApiDataFixture Magento/Store/_files/second_website_with_two_stores.php
     */
    public function testCreateWithNonDefaultStoreWebsite()
    {
        $productBuilder = $this->getSimpleProductData();
        $productBuilder[ProductInterface::SKU] = 'test-sku-second-site-123';
        $productBuilder[ProductInterface::TYPE_ID] = 'simple';
        /** @var Website $website */
        $website = $this->loadWebsiteByCode('test');

        $websitesData = [
            'website_ids' => [
                $website->getId(),
            ],
        ];
        $productBuilder[ProductInterface::EXTENSION_ATTRIBUTES_KEY] = $websitesData;
        $response = $this->saveProduct($productBuilder);
        $this->assertEquals(
            $websitesData["website_ids"],
            $response[ProductInterface::EXTENSION_ATTRIBUTES_KEY]["website_ids"]
        );
        $this->deleteProduct($productBuilder[ProductInterface::SKU]);
    }

    /**
     * Update product to be associated with website that is not associated with default store
     *
     * @magentoApiDataFixture Magento/Catalog/_files/product_with_two_websites.php
     * @magentoApiDataFixture Magento/Store/_files/second_website_with_two_stores.php
     */
    public function testUpdateWithNonDefaultStoreWebsite()
    {
        $productBuilder[ProductInterface::SKU] = 'unique-simple-azaza';
        /** @var Website $website */
        $website = $this->loadWebsiteByCode('test');

        $this->assertNotContains(Store::SCOPE_DEFAULT, $website->getStoreCodes());

        $websitesData = [
            'website_ids' => [
                $website->getId(),
            ],
        ];
        $productBuilder[ProductInterface::EXTENSION_ATTRIBUTES_KEY] = $websitesData;
        $response = $this->updateProduct($productBuilder);
        $this->assertEquals(
            $websitesData["website_ids"],
            $response[ProductInterface::EXTENSION_ATTRIBUTES_KEY]["website_ids"]
        );
    }

    /**
     * Update product without specifying websites
     *
     * @magentoApiDataFixture Magento/Catalog/_files/product_with_two_websites.php
     */
    public function testUpdateWithoutWebsiteIds()
    {
        $productBuilder[ProductInterface::SKU] = 'unique-simple-azaza';
        $originalProduct = $this->getProduct($productBuilder[ProductInterface::SKU]);
        $newName = 'Updated Product';

        $productBuilder[ProductInterface::NAME] = $newName;
        $response = $this->updateProduct($productBuilder);
        $this->assertEquals(
            $newName,
            $response[ProductInterface::NAME]
        );
        $this->assertEquals(
            $originalProduct[ProductInterface::EXTENSION_ATTRIBUTES_KEY]["website_ids"],
            $response[ProductInterface::EXTENSION_ATTRIBUTES_KEY]["website_ids"]
        );
    }

    /**
     * Test create() method
     *
     * @dataProvider productCreationProvider
     */
    public function testCreate($product)
    {
        $response = $this->saveProduct($product);
        $this->assertArrayHasKey(ProductInterface::SKU, $response);
        $this->deleteProduct($product[ProductInterface::SKU]);
    }

    /**
     * @param array $fixtureProduct
     *
     * @dataProvider productCreationProvider
     * @magentoApiDataFixture Magento/Store/_files/fixture_store_with_catalogsearch_index.php
     */
    public function testCreateAllStoreCode($fixtureProduct)
    {
        $response = $this->saveProduct($fixtureProduct, 'all');
        $this->assertArrayHasKey(ProductInterface::SKU, $response);

        /** @var \Magento\Store\Model\StoreManagerInterface $storeManager */
        $storeManager = \Magento\TestFramework\ObjectManager::getInstance()->get(
            \Magento\Store\Model\StoreManagerInterface::class
        );

        foreach ($storeManager->getStores(true) as $store) {
            $code = $store->getCode();
            if ($code === Store::ADMIN_CODE) {
                continue;
            }
            $this->assertArrayHasKey(
                ProductInterface::SKU,
                $this->getProduct($fixtureProduct[ProductInterface::SKU], $code)
            );
        }
        $this->deleteProduct($fixtureProduct[ProductInterface::SKU]);
    }

    /**
     * Test creating product with all store code on single store
     *
     * @param array $fixtureProduct
     * @dataProvider productCreationProvider
     */
    public function testCreateAllStoreCodeForSingleWebsite($fixtureProduct)
    {
        $response = $this->saveProduct($fixtureProduct, 'all');
        $this->assertArrayHasKey(ProductInterface::SKU, $response);

        /** @var \Magento\Store\Model\StoreManagerInterface $storeManager */
        $storeManager = \Magento\TestFramework\ObjectManager::getInstance()->get(
            \Magento\Store\Model\StoreManagerInterface::class
        );

        foreach ($storeManager->getStores(true) as $store) {
            $code = $store->getCode();
            if ($code === Store::ADMIN_CODE) {
                continue;
            }
            $this->assertArrayHasKey(
                ProductInterface::SKU,
                $this->getProduct($fixtureProduct[ProductInterface::SKU], $code)
            );
        }
        $this->deleteProduct($fixtureProduct[ProductInterface::SKU]);
    }

    /**
     * Test create() method with invalid price format
     */
    public function testCreateInvalidPriceFormat()
    {
        $this->_markTestAsRestOnly("In case of SOAP type casting is handled by PHP SoapServer, no need to test it");
        $expectedMessage = 'Error occurred during "price" processing. '
            . 'The "invalid_format" value\'s type is invalid. The "float" type was expected. Verify and try again.';

        try {
            $this->saveProduct(['name' => 'simple', 'price' => 'invalid_format', 'sku' => 'simple']);
            $this->fail("Expected exception was not raised");
        } catch (\Exception $e) {
            $errorObj = $this->processRestExceptionResult($e);
            $this->assertEquals($expectedMessage, $errorObj['message']);
            $this->assertEquals(HTTPExceptionCodes::HTTP_BAD_REQUEST, $e->getCode());
        }
    }

    /**
     * @param array $fixtureProduct
     *
     * @dataProvider productCreationProvider
     * @magentoApiDataFixture Magento/Store/_files/fixture_store_with_catalogsearch_index.php
     */
    public function testDeleteAllStoreCode($fixtureProduct)
    {
        $sku = $fixtureProduct[ProductInterface::SKU];
        $this->saveProduct($fixtureProduct);
        $this->expectException('Exception');
        $this->expectExceptionMessage(
            "The product that was requested doesn't exist. Verify the product and try again."
        );

        // Delete all with 'all' store code
        $this->deleteProduct($sku);
        $this->getProduct($sku);
    }

    /**
     * Test product links
     */
    public function testProductLinks()
    {
        // Create simple product
        $productData = [
            ProductInterface::SKU => "product_simple_500",
            ProductInterface::NAME => "Product Simple 500",
            ProductInterface::VISIBILITY => 4,
            ProductInterface::TYPE_ID => 'simple',
            ProductInterface::PRICE => 100,
            ProductInterface::STATUS => 1,
            ProductInterface::ATTRIBUTE_SET_ID => 4,
            ProductInterface::EXTENSION_ATTRIBUTES_KEY => [
                'stock_item' => $this->getStockItemData(),
            ],
        ];

        $this->saveProduct($productData);

        $productLinkData = [
            "sku" => "product_simple_with_related_500",
            "link_type" => "related",
            "linked_product_sku" => "product_simple_500",
            "linked_product_type" => "simple",
            "position" => 0,
        ];
        $productWithRelatedData = [
            ProductInterface::SKU => "product_simple_with_related_500",
            ProductInterface::NAME => "Product Simple with Related 500",
            ProductInterface::VISIBILITY => 4,
            ProductInterface::TYPE_ID => 'simple',
            ProductInterface::PRICE => 100,
            ProductInterface::STATUS => 1,
            ProductInterface::ATTRIBUTE_SET_ID => 4,
            "product_links" => [$productLinkData],
        ];

        $this->saveProduct($productWithRelatedData);
        $response = $this->getProduct("product_simple_with_related_500");

        $this->assertArrayHasKey('product_links', $response);
        $links = $response['product_links'];
        $this->assertCount(1, $links);
        $this->assertEquals($productLinkData, $links[0]);

        // update link information
        $productLinkData = [
            "sku" => "product_simple_with_related_500",
            "link_type" => "upsell",
            "linked_product_sku" => "product_simple_500",
            "linked_product_type" => "simple",
            "position" => 0,
        ];
        $productWithUpsellData = [
            ProductInterface::SKU => "product_simple_with_related_500",
            ProductInterface::NAME => "Product Simple with Related 500",
            ProductInterface::VISIBILITY => 4,
            ProductInterface::TYPE_ID => 'simple',
            ProductInterface::PRICE => 100,
            ProductInterface::STATUS => 1,
            ProductInterface::ATTRIBUTE_SET_ID => 4,
            "product_links" => [$productLinkData],
        ];

        $this->saveProduct($productWithUpsellData);
        $response = $this->getProduct("product_simple_with_related_500");

        $this->assertArrayHasKey('product_links', $response);
        $links = $response['product_links'];
        $this->assertCount(1, $links);
        $this->assertEquals($productLinkData, $links[0]);

        // Remove link
        $productWithNoLinkData = [
            ProductInterface::SKU => "product_simple_with_related_500",
            ProductInterface::NAME => "Product Simple with Related 500",
            ProductInterface::VISIBILITY => 4,
            ProductInterface::TYPE_ID => 'simple',
            ProductInterface::PRICE => 100,
            ProductInterface::STATUS => 1,
            ProductInterface::ATTRIBUTE_SET_ID => 4,
            "product_links" => [],
        ];

        $this->saveProduct($productWithNoLinkData);
        $response = $this->getProduct("product_simple_with_related_500");
        $this->assertArrayHasKey('product_links', $response);
        $links = $response['product_links'];
        $this->assertEquals([], $links);

        $this->deleteProduct("product_simple_500");
        $this->deleteProduct("product_simple_with_related_500");
    }

    /**
     * Get options data
     *
     * @param string $productSku
     * @return array
     */
    protected function getOptionsData($productSku)
    {
        return [
            [
                "product_sku" => $productSku,
                "title" => "DropdownOption",
                "type" => "drop_down",
                "sort_order" => 0,
                "is_require" => true,
                "values" => [
                    [
                        "title" => "DropdownOption2_1",
                        "sort_order" => 0,
                        "price" => 3,
                        "price_type" => "fixed",
                    ],
                ],
            ],
            [
                "product_sku" => $productSku,
                "title" => "CheckboxOption",
                "type" => "checkbox",
                "sort_order" => 1,
                "is_require" => false,
                "values" => [
                    [
                        "title" => "CheckBoxValue1",
                        "price" => 5,
                        "price_type" => "fixed",
                        "sort_order" => 1,
                    ],
                ],
            ],
        ];
    }

    /**
     * Test product options
     */
    public function testProductOptions()
    {
        //Create product with options
        $productData = $this->getSimpleProductData();
        $optionsDataInput = $this->getOptionsData($productData['sku']);
        $productData['options'] = $optionsDataInput;
        $this->saveProduct($productData);
        $response = $this->getProduct($productData[ProductInterface::SKU]);

        $this->assertArrayHasKey('options', $response);
        $options = $response['options'];
        $this->assertCount(2, $options);
        $this->assertCount(1, $options[0]['values']);
        $this->assertCount(1, $options[1]['values']);

        //update the product options, adding a value to option 1, delete an option and create a new option
        $options[0]['values'][] = [
            "title" => "Value2",
            "price" => 6,
            "price_type" => "fixed",
            'sort_order' => 3,
        ];
        $options[1] = [
            "product_sku" => $productData['sku'],
            "title" => "DropdownOption2",
            "type" => "drop_down",
            "sort_order" => 3,
            "is_require" => false,
            "values" => [
                [
                    "title" => "Value3",
                    "price" => 7,
                    "price_type" => "fixed",
                    "sort_order" => 4,
                ],
            ],
        ];
        $response['options'] = $options;
        $response = $this->updateProduct($response);
        $this->assertArrayHasKey('options', $response);
        $options = $response['options'];
        $this->assertCount(2, $options);
        $this->assertCount(2, $options[0]['values']);
        $this->assertCount(1, $options[1]['values']);

        //update product without setting options field, option should not be changed
        unset($response['options']);
        $this->updateProduct($response);
        $response = $this->getProduct($productData[ProductInterface::SKU]);
        $this->assertArrayHasKey('options', $response);
        $options = $response['options'];
        $this->assertCount(2, $options);

        //update product with empty options, options should be removed
        $response['options'] = [];
        $response = $this->updateProduct($response);
        $this->assertEmpty($response['options']);

        $this->deleteProduct($productData[ProductInterface::SKU]);
    }

    /**
     * Test product with media gallery
     */
    public function testProductWithMediaGallery()
    {
        $encodedImage = $this->getTestImage();
        //create a product with media gallery
        $filename1 = 'tiny1' . time() . '.jpg';
        $filename2 = 'tiny2' . time() . '.jpeg';
        $productData = $this->getSimpleProductData();
        $productData['media_gallery_entries'] = [
            $this->getMediaGalleryData($filename1, $encodedImage, 1, 'tiny1', true),
            $this->getMediaGalleryData($filename2, $encodedImage, 2, 'tiny2', false),
        ];
        $response = $this->saveProduct($productData);
        $this->assertArrayHasKey('media_gallery_entries', $response);
        $mediaGalleryEntries = $response['media_gallery_entries'];
        $this->assertCount(2, $mediaGalleryEntries);
        $id = $mediaGalleryEntries[0]['id'];
        foreach ($mediaGalleryEntries as &$entry) {
            unset($entry['id']);
        }
        $expectedValue = [
            [
                'label' => 'tiny1',
                'position' => 1,
                'media_type' => 'image',
                'disabled' => true,
                'types' => [],
                'file' => '/t/i/' . $filename1,
            ],
            [
                'label' => 'tiny2',
                'position' => 2,
                'media_type' => 'image',
                'disabled' => false,
                'types' => [],
                'file' => '/t/i/' . $filename2,
            ],
        ];
        $this->assertEquals($expectedValue, $mediaGalleryEntries);
        //update the product media gallery
        $response['media_gallery_entries'] = [
            [
                'id' => $id,
                'media_type' => 'image',
                'label' => 'tiny1_new_label',
                'position' => 1,
                'disabled' => false,
                'types' => [],
                'file' => '/t/i/' . $filename1,
            ],
        ];
        $response = $this->updateProduct($response);
        $mediaGalleryEntries = $response['media_gallery_entries'];
        $this->assertCount(1, $mediaGalleryEntries);
        unset($mediaGalleryEntries[0]['id']);
        $expectedValue = [
            [
                'label' => 'tiny1_new_label',
                'media_type' => 'image',
                'position' => 1,
                'disabled' => false,
                'types' => [],
                'file' => '/t/i/' . $filename1,
            ],
        ];
        $this->assertEquals($expectedValue, $mediaGalleryEntries);
        //don't set the media_gallery_entries field, existing entry should not be touched
        unset($response['media_gallery_entries']);
        $response = $this->updateProduct($response);
        $mediaGalleryEntries = $response['media_gallery_entries'];
        $this->assertCount(1, $mediaGalleryEntries);
        unset($mediaGalleryEntries[0]['id']);
        $this->assertEquals($expectedValue, $mediaGalleryEntries);
        //pass empty array, delete all existing media gallery entries
        $response['media_gallery_entries'] = [];
        $response = $this->updateProduct($response);
        $this->assertEmpty($response['media_gallery_entries']);
        $this->deleteProduct($productData[ProductInterface::SKU]);
    }

    /**
     * Test update() method
     *
     * @magentoApiDataFixture Magento/Catalog/_files/product_simple.php
     */
    public function testUpdate()
    {
        $productData = [
            ProductInterface::NAME => 'Very Simple Product', //new name
            ProductInterface::SKU => 'simple', //sku from fixture
        ];
        $product = $this->getSimpleProductData($productData);
        $response = $this->updateProduct($product);

        $this->assertArrayHasKey(ProductInterface::SKU, $response);
        $this->assertArrayHasKey(ProductInterface::NAME, $response);
        $this->assertEquals($productData[ProductInterface::NAME], $response[ProductInterface::NAME]);
        $this->assertEquals($productData[ProductInterface::SKU], $response[ProductInterface::SKU]);
    }

    /**
     * Update product with extension attributes.
     *
     * @magentoApiDataFixture Magento/Downloadable/_files/product_downloadable.php
     */
    public function testUpdateWithExtensionAttributes(): void
    {
        $sku = 'downloadable-product';
        $linksKey = 'downloadable_product_links';
        $productData = [
            ProductInterface::NAME => 'Downloadable (updated)',
            ProductInterface::SKU => $sku,
        ];
        $response = $this->updateProduct($productData);

        self::assertArrayHasKey(ExtensibleDataInterface::EXTENSION_ATTRIBUTES_KEY, $response);
        self::assertArrayHasKey($linksKey, $response[ExtensibleDataInterface::EXTENSION_ATTRIBUTES_KEY]);
        self::assertCount(1, $response[ExtensibleDataInterface::EXTENSION_ATTRIBUTES_KEY][$linksKey]);

        $linkData = $response[ExtensibleDataInterface::EXTENSION_ATTRIBUTES_KEY][$linksKey][0];

        self::assertArrayHasKey(Link::KEY_LINK_URL, $linkData);
        self::assertEquals('http://example.com/downloadable.txt', $linkData[Link::KEY_LINK_URL]);
    }

    /**
     * Update product
     *
     * @param array $product
     * @param string|null $token
     * @return array|bool|float|int|string
     */
    protected function updateProduct($product, ?string $token = null)
    {
        if (isset($product['custom_attributes'])) {
            foreach ($product['custom_attributes'] as &$attribute) {
                if ($attribute['attribute_code'] == 'category_ids' && !is_array($attribute['value'])) {
                    $attribute['value'] = [""];
                }
            }
        }
        $sku = $product[ProductInterface::SKU];
        if (TESTS_WEB_API_ADAPTER == self::ADAPTER_REST) {
            $product[ProductInterface::SKU] = null;
        }

        $serviceInfo = [
            'rest' => [
                'resourcePath' => self::RESOURCE_PATH . '/' . $sku,
                'httpMethod' => Request::HTTP_METHOD_PUT,
            ],
            'soap' => [
                'service' => self::SERVICE_NAME,
                'serviceVersion' => self::SERVICE_VERSION,
                'operation' => self::SERVICE_NAME . 'Save',
            ],
        ];
        if ($token) {
            $serviceInfo['rest']['token'] = $serviceInfo['soap']['token'] = $token;
        }
        $requestData = ['product' => $product];
        $response = $this->_webApiCall($serviceInfo, $requestData);
        return $response;
    }

    /**
     * Test delete() method
     *
     * @magentoApiDataFixture Magento/Catalog/_files/product_simple.php
     */
    public function testDelete()
    {
        $response = $this->deleteProduct('simple');
        $this->assertTrue($response);
    }

    /**
     * Test getList() method
     *
     * @magentoApiDataFixture Magento/Catalog/_files/product_simple.php
     */
    public function testGetList()
    {
        $searchCriteria = [
            'searchCriteria' => [
                'filter_groups' => [
                    [
                        'filters' => [
                            [
                                'field' => 'sku',
                                'value' => 'simple',
                                'condition_type' => 'eq',
                            ],
                        ],
                    ],
                ],
                'current_page' => 1,
                'page_size' => 2,
            ],
        ];

        $serviceInfo = [
            'rest' => [
                'resourcePath' => self::RESOURCE_PATH . '?' . http_build_query($searchCriteria),
                'httpMethod' => Request::HTTP_METHOD_GET,
            ],
            'soap' => [
                'service' => self::SERVICE_NAME,
                'serviceVersion' => self::SERVICE_VERSION,
                'operation' => self::SERVICE_NAME . 'GetList',
            ],
        ];

        $response = $this->_webApiCall($serviceInfo, $searchCriteria);

        $this->assertArrayHasKey('search_criteria', $response);
        $this->assertArrayHasKey('total_count', $response);
        $this->assertArrayHasKey('items', $response);

        $this->assertEquals($searchCriteria['searchCriteria'], $response['search_criteria']);
        $this->assertTrue($response['total_count'] > 0);
        $this->assertTrue(count($response['items']) > 0);

        $this->assertNotNull($response['items'][0]['sku']);
        $this->assertNotNull($response['items'][0][ExtensibleDataInterface::EXTENSION_ATTRIBUTES_KEY]['website_ids']);
        $this->assertEquals('simple', $response['items'][0]['sku']);

        $index = null;
        foreach ($response['items'][0]['custom_attributes'] as $key => $customAttribute) {
            if ($customAttribute['attribute_code'] == 'category_ids') {
                $index = $key;
                break;
            }
        }
        $this->assertNotNull($index, 'Category information wasn\'t set');

        $expectedResult = (TESTS_WEB_API_ADAPTER == self::ADAPTER_SOAP) ? ['string' => '2'] : ['2'];
        $this->assertEquals($expectedResult, $response['items'][0]['custom_attributes'][$index]['value']);
    }

    /**
     * Test getList() method with additional params
     *
     * @magentoApiDataFixture Magento/Catalog/_files/product_simple.php
     */
    public function testGetListWithAdditionalParams()
    {
        $this->_markTestAsRestOnly();
        $searchCriteria = [
            'searchCriteria' => [
                'current_page' => 1,
                'page_size' => 2,
            ],
        ];
        $additionalParams = urlencode('items[id,custom_attributes[description]]');

        $serviceInfo = [
            'rest' => [
                'resourcePath' => self::RESOURCE_PATH . '?' . http_build_query($searchCriteria) . '&fields=' .
                    $additionalParams,
                'httpMethod' => Request::HTTP_METHOD_GET,
            ],
        ];

        $response = $this->_webApiCall($serviceInfo, $searchCriteria);

        $this->assertArrayHasKey('items', $response);
        $this->assertTrue(count($response['items']) > 0);

        $indexDescription = null;
        foreach ($response['items'][0]['custom_attributes'] as $key => $customAttribute) {
            if ($customAttribute['attribute_code'] == 'description') {
                $indexDescription = $key;
            }
        }

        $this->assertNotNull($response['items'][0]['custom_attributes'][$indexDescription]['attribute_code']);
        $this->assertNotNull($response['items'][0]['custom_attributes'][$indexDescription]['value']);
        $this->assertTrue(count($response['items'][0]['custom_attributes']) == 1);
    }

    /**
     * Test getList() method with filtering by website
     *
     * @magentoApiDataFixture Magento/Catalog/_files/products_with_websites_and_stores.php
     * @return void
     */
    public function testGetListWithFilteringByWebsite()
    {
        $website = $this->loadWebsiteByCode('test');
        $searchCriteria = [
            'searchCriteria' => [
                'filter_groups' => [
                    [
                        'filters' => [
                            [
                                'field' => 'website_id',
                                'value' => $website->getId(),
                                'condition_type' => 'eq',
                            ],
                        ],
                    ],
                ],
                'current_page' => 1,
                'page_size' => 10,
            ],
        ];
        $serviceInfo = [
            'rest' => [
                'resourcePath' => self::RESOURCE_PATH . '?' . http_build_query($searchCriteria),
                'httpMethod' => Request::HTTP_METHOD_GET,
            ],
            'soap' => [
                'service' => self::SERVICE_NAME,
                'serviceVersion' => self::SERVICE_VERSION,
                'operation' => self::SERVICE_NAME . 'GetList',
            ],
        ];
        $response = $this->_webApiCall($serviceInfo, $searchCriteria);

        $this->assertArrayHasKey('search_criteria', $response);
        $this->assertArrayHasKey('total_count', $response);
        $this->assertArrayHasKey('items', $response);
        $this->assertTrue(count($response['items']) == 1);
        $this->assertTrue(isset($response['items'][0]['sku']));
        $this->assertEquals('simple-2', $response['items'][0]['sku']);
        $this->assertNotNull($response['items'][0][ExtensibleDataInterface::EXTENSION_ATTRIBUTES_KEY]['website_ids']);
    }

    /**
     * @magentoApiDataFixture Magento/Catalog/_files/products_with_websites_and_stores.php
     * @dataProvider testGetListWithFilteringByStoreDataProvider
     *
     * @param array $searchCriteria
     * @param array $skus
     * @param int $expectedProductCount
     * @return void
     */
    public function testGetListWithFilteringByStore(array $searchCriteria, array $skus, $expectedProductCount = null)
    {
        $serviceInfo = [
            'rest' => [
                'resourcePath' => self::RESOURCE_PATH . '?' . http_build_query($searchCriteria),
                'httpMethod' => Request::HTTP_METHOD_GET,
            ],
            'soap' => [
                'service' => self::SERVICE_NAME,
                'serviceVersion' => self::SERVICE_VERSION,
                'operation' => self::SERVICE_NAME . 'GetList',
            ],
        ];
        $response = $this->_webApiCall($serviceInfo, $searchCriteria);

        $this->assertArrayHasKey('search_criteria', $response);
        $this->assertArrayHasKey('total_count', $response);
        $this->assertArrayHasKey('items', $response);
        if ($expectedProductCount) {
            $this->assertTrue(count($response['items']) == $expectedProductCount);
        }

        $isResultValid = false;
        foreach ($skus as $sku) {
            foreach ($response['items'] as $item) {
                if ($item['sku'] == $sku) {
                    $isResultValid = true;
                }
            }
            $this->assertTrue($isResultValid);
        }
    }

    /**
     * Test getList() method with filtering by store data provider
     *
     * @return array
     */
    public function testGetListWithFilteringByStoreDataProvider()
    {
        return [
            [
                [
                    'searchCriteria' => [
                        'filter_groups' => [
                            [
                                'filters' => [
                                    [
                                        'field' => 'store',
                                        'value' => 'fixture_second_store',
                                        'condition_type' => 'eq',
                                    ],
                                ],
                            ],
                        ],
                        'current_page' => 1,
                        'page_size' => 10,
                    ],
                ],
                ['simple-2'],
                1,
            ],
            [
                [
                    'searchCriteria' => [
                        'filter_groups' => [],
                        'current_page' => 1,
                        'page_size' => 10,
                    ],
                ],
                ['simple-2', 'simple-1'],
                null,
            ],
        ];
    }

    /**
     * Test getList() method with pagination
     *
     * @param int $pageSize
     * @param int $currentPage
     * @param int $expectedCount
     *
     * @magentoAppIsolation enabled
     * @magentoApiDataFixture Magento/Catalog/_files/products_for_search.php
     * @dataProvider productPaginationDataProvider
     */
    public function testGetListPagination(int $pageSize, int $currentPage, int $expectedCount)
    {
        $fixtureProducts = 5;

        /** @var FilterBuilder $filterBuilder */
        $filterBuilder = Bootstrap::getObjectManager()->create(FilterBuilder::class);

        $categoryFilter = $filterBuilder->setField('category_id')
            ->setValue(333)
            ->create();

        /** @var SearchCriteriaBuilder $searchCriteriaBuilder */
        $searchCriteriaBuilder = Bootstrap::getObjectManager()->create(SearchCriteriaBuilder::class);

        $searchCriteriaBuilder->addFilters([$categoryFilter]);
        $searchCriteriaBuilder->setPageSize($pageSize);
        $searchCriteriaBuilder->setCurrentPage($currentPage);

        $searchData = $searchCriteriaBuilder->create()->__toArray();
        $requestData = ['searchCriteria' => $searchData];
        $serviceInfo = [
            'rest' => [
                'resourcePath' => self::RESOURCE_PATH . '?' . http_build_query($requestData),
                'httpMethod' => Request::HTTP_METHOD_GET,
            ],
            'soap' => [
                'service' => self::SERVICE_NAME,
                'serviceVersion' => self::SERVICE_VERSION,
                'operation' => self::SERVICE_NAME . 'GetList',
            ],
        ];

        $searchResult = $this->_webApiCall($serviceInfo, $requestData);

        $this->assertEquals($fixtureProducts, $searchResult['total_count']);
        $this->assertCount($expectedCount, $searchResult['items']);
    }

    /**
     * Keep in mind: Fixture contains 5 products
     *
     * @return array
     */
    public function productPaginationDataProvider()
    {
        return [
            'expect-all-items' => [
                'pageSize' => 10,
                'currentPage' => 1,
                'expectedCount' => 5,
            ],
            'expect-page=size-items' => [
                'pageSize' => 2,
                'currentPage' => 1,
                'expectedCount' => 2,
            ],
            'expect-less-than-pagesize-elements' => [
                'pageSize' => 3,
                'currentPage' => 2,
                'expectedCount' => 2,
            ],
            'expect-no-items' => [
                'pageSize' => 100,
                'currentPage' => 99,
                'expectedCount' => 0,
            ],
        ];
    }

    /**
     * Test getList() method with multiple filter groups and sorting and pagination
     *
     * @magentoApiDataFixture Magento/Catalog/_files/products_for_search.php
     */
    public function testGetListWithMultipleFilterGroupsAndSortingAndPagination()
    {
        /** @var FilterBuilder $filterBuilder */
        $filterBuilder = Bootstrap::getObjectManager()->create(FilterBuilder::class);

        $filter1 = $filterBuilder->setField(ProductInterface::NAME)
            ->setValue('search product 2')
            ->create();
        $filter2 = $filterBuilder->setField(ProductInterface::NAME)
            ->setValue('search product 3')
            ->create();
        $filter3 = $filterBuilder->setField(ProductInterface::NAME)
            ->setValue('search product 4')
            ->create();
        $filter4 = $filterBuilder->setField(ProductInterface::NAME)
            ->setValue('search product 5')
            ->create();
        $filter5 = $filterBuilder->setField(ProductInterface::PRICE)
            ->setValue(35)
            ->setConditionType('lt')
            ->create();
        $filter6 = $filterBuilder->setField('category_id')
            ->setValue(333)
            ->create();

        /**@var SortOrderBuilder $sortOrderBuilder */
        $sortOrderBuilder = Bootstrap::getObjectManager()->create(SortOrderBuilder::class);

        /** @var SortOrder $sortOrder */
        $sortOrder = $sortOrderBuilder->setField('meta_title')->setDirection(SortOrder::SORT_DESC)->create();

        /** @var SearchCriteriaBuilder $searchCriteriaBuilder */
        $searchCriteriaBuilder = Bootstrap::getObjectManager()->create(SearchCriteriaBuilder::class);

        $searchCriteriaBuilder->addFilters([$filter1, $filter2, $filter3, $filter4]);
        $searchCriteriaBuilder->addFilters([$filter5]);
        $searchCriteriaBuilder->addFilters([$filter6]);
        $searchCriteriaBuilder->setSortOrders([$sortOrder]);

        $searchCriteriaBuilder->setPageSize(2);
        $searchCriteriaBuilder->setCurrentPage(2);

        $searchData = $searchCriteriaBuilder->create()->__toArray();
        $requestData = ['searchCriteria' => $searchData];
        $serviceInfo = [
            'rest' => [
                'resourcePath' => self::RESOURCE_PATH . '?' . http_build_query($requestData),
                'httpMethod' => Request::HTTP_METHOD_GET,
            ],
            'soap' => [
                'service' => self::SERVICE_NAME,
                'serviceVersion' => self::SERVICE_VERSION,
                'operation' => self::SERVICE_NAME . 'GetList',
            ],
        ];

        $searchResult = $this->_webApiCall($serviceInfo, $requestData);

        $this->assertEquals(3, $searchResult['total_count']);
        $this->assertCount(1, $searchResult['items']);
        $this->assertEquals('search_product_4', $searchResult['items'][0][ProductInterface::SKU]);
        $this->assertNotNull(
            $searchResult['items'][0][ExtensibleDataInterface::EXTENSION_ATTRIBUTES_KEY]['website_ids']
        );
    }

    /**
     * Test get list filter by category sorting by position.
     *
     * @magentoApiDataFixture Magento/Catalog/_files/products_for_search.php
     * @dataProvider getListSortingByPositionDataProvider
     *
     * @param string $sortOrder
     * @param array $expectedItems
     */
    public function testGetListSortingByPosition(string $sortOrder, array $expectedItems): void
    {
        $sortOrderBuilder = Bootstrap::getObjectManager()->create(SortOrderBuilder::class);
        $searchCriteriaBuilder = Bootstrap::getObjectManager()->create(SearchCriteriaBuilder::class);
        $sortOrder = $sortOrderBuilder->setField('position')->setDirection($sortOrder)->create();
        $searchCriteriaBuilder->addFilter('category_id', 333);
        $searchCriteriaBuilder->addSortOrder($sortOrder);
        $searchCriteriaBuilder->setPageSize(5);
        $searchCriteriaBuilder->setCurrentPage(1);
        $searchData = $searchCriteriaBuilder->create()->__toArray();
        $requestData = ['searchCriteria' => $searchData];
        $serviceInfo = [
            'rest' => [
                'resourcePath' => self::RESOURCE_PATH . '?' . http_build_query($requestData),
                'httpMethod' => Request::HTTP_METHOD_GET,
            ],
            'soap' => [
                'service' => self::SERVICE_NAME,
                'serviceVersion' => self::SERVICE_VERSION,
                'operation' => self::SERVICE_NAME . 'GetList',
            ],
        ];

        $searchResult = $this->_webApiCall($serviceInfo, $requestData);

        $this->assertEquals(5, $searchResult['total_count']);
        $this->assertEquals($expectedItems[0], $searchResult['items'][0]['sku']);
        $this->assertEquals($expectedItems[1], $searchResult['items'][1]['sku']);
        $this->assertEquals($expectedItems[2], $searchResult['items'][2]['sku']);
        $this->assertEquals($expectedItems[3], $searchResult['items'][3]['sku']);
        $this->assertEquals($expectedItems[4], $searchResult['items'][4]['sku']);
    }

    /**
     * Provides data for testGetListSortingByPosition().
     *
     * @return array[]
     */
    public function getListSortingByPositionDataProvider(): array
    {
        return [
            'sort_by_position_descending' => [
                'direction' => SortOrder::SORT_DESC,
                'expectedItems' => [
                    'search_product_5',
                    'search_product_4',
                    'search_product_3',
                    'search_product_2',
                    'search_product_1',
                ],
            ],
            'sort_by_position_ascending' => [
                'direction' => SortOrder::SORT_ASC,
                'expectedItems' => [
                    'search_product_1',
                    'search_product_2',
                    'search_product_3',
                    'search_product_4',
                    'search_product_5',
                ],
            ],
        ];
    }

    /**
     * Convert custom attributes to associative array
     *
     * @param $customAttributes
     * @return array
     */
    protected function convertCustomAttributesToAssociativeArray($customAttributes)
    {
        $converted = [];
        foreach ($customAttributes as $customAttribute) {
            $converted[$customAttribute['attribute_code']] = $customAttribute['value'];
        }

        return $converted;
    }

    /**
     * Convert associative array to custom attributes
     *
     * @param $data
     * @return array
     */
    protected function convertAssociativeArrayToCustomAttributes($data)
    {
        $customAttributes = [];
        foreach ($data as $attributeCode => $attributeValue) {
            $customAttributes[] = ['attribute_code' => $attributeCode, 'value' => $attributeValue];
        }

        return $customAttributes;
    }

    /**
     * Test eav attributes
     *
     * @magentoApiDataFixture Magento/Catalog/_files/product_simple.php
     */
    public function testEavAttributes()
    {
        $response = $this->getProduct('simple');

        $this->assertNotEmpty($response['custom_attributes']);
        $customAttributesData = $this->convertCustomAttributesToAssociativeArray($response['custom_attributes']);
        $this->assertNotTrue(isset($customAttributesData['name']));
        $this->assertNotTrue(isset($customAttributesData['tier_price']));

        //Set description
        $descriptionValue = "new description";
        $customAttributesData['description'] = $descriptionValue;
        $response['custom_attributes'] = $this->convertAssociativeArrayToCustomAttributes($customAttributesData);

        $response = $this->updateProduct($response);
        $this->assertNotEmpty($response['custom_attributes']);

        $customAttributesData = $this->convertCustomAttributesToAssociativeArray($response['custom_attributes']);
        $this->assertTrue(isset($customAttributesData['description']));
        $this->assertEquals($descriptionValue, $customAttributesData['description']);

        $this->deleteProduct('simple');
    }

    /**
     * Get Simple Product Data
     *
     * @param array $productData
     * @return array
     */
    protected function getSimpleProductData($productData = [])
    {
        return [
            ProductInterface::SKU => isset($productData[ProductInterface::SKU])
                ? $productData[ProductInterface::SKU] : uniqid('sku-', true),
            ProductInterface::NAME => isset($productData[ProductInterface::NAME])
                ? $productData[ProductInterface::NAME] : uniqid('sku-', true),
            ProductInterface::VISIBILITY => 4,
            ProductInterface::TYPE_ID => 'simple',
            ProductInterface::PRICE => 3.62,
            ProductInterface::STATUS => 1,
            ProductInterface::ATTRIBUTE_SET_ID => 4,
            'custom_attributes' => [
                ['attribute_code' => 'cost', 'value' => ''],
                ['attribute_code' => 'description', 'value' => 'Description'],
            ],
        ];
    }

    /**
     * Save Product
     *
     * @param $product
     * @param string|null $storeCode
     * @param string|null $token
     * @return mixed
     */
    protected function saveProduct($product, $storeCode = null, ?string $token = null)
    {
        if (isset($product['custom_attributes'])) {
            foreach ($product['custom_attributes'] as &$attribute) {
                if ($attribute['attribute_code'] == 'category_ids'
                    && !is_array($attribute['value'])
                ) {
                    $attribute['value'] = [""];
                }
            }
        }
        $serviceInfo = [
            'rest' => [
                'resourcePath' => self::RESOURCE_PATH,
                'httpMethod' => Request::HTTP_METHOD_POST,
            ],
            'soap' => [
                'service' => self::SERVICE_NAME,
                'serviceVersion' => self::SERVICE_VERSION,
                'operation' => self::SERVICE_NAME . 'Save',
            ],
        ];
        if ($token) {
            $serviceInfo['rest']['token'] = $serviceInfo['soap']['token'] = $token;
        }
        $requestData = ['product' => $product];

        return $this->_webApiCall($serviceInfo, $requestData, null, $storeCode);
    }

    /**
     * Delete Product
     *
     * @param string $sku
     * @return boolean
     */
    protected function deleteProduct($sku)
    {
        $serviceInfo = [
            'rest' => [
                'resourcePath' => self::RESOURCE_PATH . '/' . $sku,
                'httpMethod' => Request::HTTP_METHOD_DELETE,
            ],
            'soap' => [
                'service' => self::SERVICE_NAME,
                'serviceVersion' => self::SERVICE_VERSION,
                'operation' => self::SERVICE_NAME . 'DeleteById',
            ],
        ];

        return (TESTS_WEB_API_ADAPTER == self::ADAPTER_SOAP) ?
            $this->_webApiCall($serviceInfo, ['sku' => $sku]) : $this->_webApiCall($serviceInfo);
    }

    /**
     * Test tier prices
     */
    public function testTierPrices()
    {
        // create a product with tier prices
        $custGroup1 = \Magento\Customer\Model\Group::NOT_LOGGED_IN_ID;
        $custGroup2 = \Magento\Customer\Model\Group::CUST_GROUP_ALL;
        $productData = $this->getSimpleProductData();
        $productData[self::KEY_TIER_PRICES] = [
            [
                'customer_group_id' => $custGroup1,
                'value' => 3.14,
                'qty' => 5,
            ],
            [
                'customer_group_id' => $custGroup2,
                'value' => 3.45,
                'qty' => 10,
            ],
        ];
        $this->saveProduct($productData);
        $response = $this->getProduct($productData[ProductInterface::SKU]);

        $this->assertArrayHasKey(self::KEY_TIER_PRICES, $response);
        $tierPrices = $response[self::KEY_TIER_PRICES];
        $this->assertNotNull($tierPrices, "CREATE: expected to have tier prices");
        $this->assertCount(2, $tierPrices, "CREATE: expected to have 2 'tier_prices' objects");
        $this->assertEquals(3.14, $tierPrices[0]['value']);
        $this->assertEquals(5, $tierPrices[0]['qty']);
        $this->assertEquals($custGroup1, $tierPrices[0]['customer_group_id']);
        $this->assertEquals(3.45, $tierPrices[1]['value']);
        $this->assertEquals(10, $tierPrices[1]['qty']);
        $this->assertEquals($custGroup2, $tierPrices[1]['customer_group_id']);

        // update the product's tier prices: update 1st tier price, (delete the 2nd tier price), add a new one
        $custGroup3 = 1;
        $tierPrices[0]['value'] = 3.33;
        $tierPrices[0]['qty'] = 6;
        $tierPrices[1] = [
            'customer_group_id' => $custGroup3,
            'value' => 2.10,
            'qty' => 12,
        ];
        $response[self::KEY_TIER_PRICES] = $tierPrices;
        $response = $this->updateProduct($response);

        $this->assertArrayHasKey(self::KEY_TIER_PRICES, $response);
        $tierPrices = $response[self::KEY_TIER_PRICES];
        $this->assertNotNull($tierPrices, "UPDATE 1: expected to have tier prices");
        $this->assertCount(2, $tierPrices, "UPDATE 1: expected to have 2 'tier_prices' objects");
        $this->assertEquals(3.33, $tierPrices[0]['value']);
        $this->assertEquals(6, $tierPrices[0]['qty']);
        $this->assertEquals($custGroup1, $tierPrices[0]['customer_group_id']);
        $this->assertEquals(2.10, $tierPrices[1]['value']);
        $this->assertEquals(12, $tierPrices[1]['qty']);
        $this->assertEquals($custGroup3, $tierPrices[1]['customer_group_id']);

        // update the product without any mention of tier prices; no change expected for tier pricing
        $response = $this->getProduct($productData[ProductInterface::SKU]);
        unset($response[self::KEY_TIER_PRICES]);
        $response = $this->updateProduct($response);

        $this->assertArrayHasKey(self::KEY_TIER_PRICES, $response);
        $tierPrices = $response[self::KEY_TIER_PRICES];
        $this->assertNotNull($tierPrices, "UPDATE 2: expected to have tier prices");
        $this->assertCount(2, $tierPrices, "UPDATE 2: expected to have 2 'tier_prices' objects");
        $this->assertEquals(3.33, $tierPrices[0]['value']);
        $this->assertEquals(6, $tierPrices[0]['qty']);
        $this->assertEquals($custGroup1, $tierPrices[0]['customer_group_id']);
        $this->assertEquals(2.10, $tierPrices[1]['value']);
        $this->assertEquals(12, $tierPrices[1]['qty']);
        $this->assertEquals($custGroup3, $tierPrices[1]['customer_group_id']);

        // update the product with empty tier prices; expect to have the existing tier prices removed
        $response = $this->getProduct($productData[ProductInterface::SKU]);
        $response[self::KEY_TIER_PRICES] = [];
        $response = $this->updateProduct($response);

        // delete the product with tier prices; expect that all goes well
        $response = $this->deleteProduct($productData[ProductInterface::SKU]);
        $this->assertTrue($response);
    }

    /**
     * Get stock item data
     *
     * @return array
     */
    private function getStockItemData()
    {
        return [
            StockItemInterface::IS_IN_STOCK => 1,
            StockItemInterface::QTY => 100500,
            StockItemInterface::IS_QTY_DECIMAL => 1,
            StockItemInterface::SHOW_DEFAULT_NOTIFICATION_MESSAGE => 0,
            StockItemInterface::USE_CONFIG_MIN_QTY => 0,
            StockItemInterface::USE_CONFIG_MIN_SALE_QTY => 0,
            StockItemInterface::MIN_QTY => 1,
            StockItemInterface::MIN_SALE_QTY => 1,
            StockItemInterface::MAX_SALE_QTY => 100,
            StockItemInterface::USE_CONFIG_MAX_SALE_QTY => 0,
            StockItemInterface::USE_CONFIG_BACKORDERS => 0,
            StockItemInterface::BACKORDERS => 0,
            StockItemInterface::USE_CONFIG_NOTIFY_STOCK_QTY => 0,
            StockItemInterface::NOTIFY_STOCK_QTY => 0,
            StockItemInterface::USE_CONFIG_QTY_INCREMENTS => 0,
            StockItemInterface::QTY_INCREMENTS => 0,
            StockItemInterface::USE_CONFIG_ENABLE_QTY_INC => 0,
            StockItemInterface::ENABLE_QTY_INCREMENTS => 0,
            StockItemInterface::USE_CONFIG_MANAGE_STOCK => 1,
            StockItemInterface::MANAGE_STOCK => 1,
            StockItemInterface::LOW_STOCK_DATE => null,
            StockItemInterface::IS_DECIMAL_DIVIDED => 0,
            StockItemInterface::STOCK_STATUS_CHANGED_AUTO => 0,
        ];
    }

    /**
     * Test product category links
     *
     * @magentoApiDataFixture Magento/Catalog/_files/category_product.php
     */
    public function testProductCategoryLinks()
    {
        // Create simple product
        $productData = $this->getSimpleProductData();
        $productData[ProductInterface::EXTENSION_ATTRIBUTES_KEY] = [
            self::KEY_CATEGORY_LINKS => [['category_id' => 333, 'position' => 0]],
        ];
        $response = $this->saveProduct($productData);
        $this->assertEquals(
            [['category_id' => 333, 'position' => 0]],
            $response[ProductInterface::EXTENSION_ATTRIBUTES_KEY][self::KEY_CATEGORY_LINKS]
        );
        $response = $this->getProduct($productData[ProductInterface::SKU]);
        $this->assertArrayHasKey(ProductInterface::EXTENSION_ATTRIBUTES_KEY, $response);
        $extensionAttributes = $response[ProductInterface::EXTENSION_ATTRIBUTES_KEY];
        $this->assertArrayHasKey(self::KEY_CATEGORY_LINKS, $extensionAttributes);
        $this->assertEquals([['category_id' => 333, 'position' => 0]], $extensionAttributes[self::KEY_CATEGORY_LINKS]);
    }

    /**
     * Test update product category without categories
     *
     * @magentoApiDataFixture Magento/Catalog/_files/category_product.php
     */
    public function testUpdateProductCategoryLinksNullOrNotExists()
    {
        $response = $this->getProduct('simple333');
        // update product without category_link or category_link is null
        $response[ProductInterface::EXTENSION_ATTRIBUTES_KEY][self::KEY_CATEGORY_LINKS] = null;
        $response = $this->updateProduct($response);
        $this->assertEquals(
            [['category_id' => 333, 'position' => 0]],
            $response[ProductInterface::EXTENSION_ATTRIBUTES_KEY][self::KEY_CATEGORY_LINKS]
        );
        unset($response[ProductInterface::EXTENSION_ATTRIBUTES_KEY][self::KEY_CATEGORY_LINKS]);
        $response = $this->updateProduct($response);
        $this->assertEquals(
            [['category_id' => 333, 'position' => 0]],
            $response[ProductInterface::EXTENSION_ATTRIBUTES_KEY][self::KEY_CATEGORY_LINKS]
        );
    }

    /**
     * Test update product category links position
     *
     * @magentoApiDataFixture Magento/Catalog/_files/category_product.php
     */
    public function testUpdateProductCategoryLinksPosistion()
    {
        $response = $this->getProduct('simple333');
        // update category_link position
        $response[ProductInterface::EXTENSION_ATTRIBUTES_KEY][self::KEY_CATEGORY_LINKS] = [
            ['category_id' => 333, 'position' => 10],
        ];
        $response = $this->updateProduct($response);
        $this->assertEquals(
            [['category_id' => 333, 'position' => 10]],
            $response[ProductInterface::EXTENSION_ATTRIBUTES_KEY][self::KEY_CATEGORY_LINKS]
        );
    }

    /**
     * Test update product category links unassing
     *
     * @magentoApiDataFixture Magento/Catalog/_files/category_product.php
     */
    public function testUpdateProductCategoryLinksUnassign()
    {
        $response = $this->getProduct('simple333');
        // unassign category_links from product
        $response[ProductInterface::EXTENSION_ATTRIBUTES_KEY][self::KEY_CATEGORY_LINKS] = [];
        $response = $this->updateProduct($response);
        $this->assertArrayNotHasKey(self::KEY_CATEGORY_LINKS, $response[ProductInterface::EXTENSION_ATTRIBUTES_KEY]);
    }

    /**
     * Get media gallery data
     *
     * @param string $filename
     * @param string $encodedImage
     * @param int $position
     * @param string $label
     * @param bool $disabled
     * @param array $types
     * @return array
     */
    private function getMediaGalleryData(
        string $filename,
        string $encodedImage,
        int $position,
        string $label,
        bool $disabled = false,
        array $types = []
    ): array {
        return [
            'position' => $position,
            'media_type' => 'image',
            'disabled' => $disabled,
            'label' => $label,
            'types' => $types,
            'content' => [
                'type' => 'image/jpeg',
                'name' => $filename,
                'base64_encoded_data' => $encodedImage,
            ],
        ];
    }

    /**
     * Test special price
     */
    public function testSpecialPrice()
    {
        $productData = $this->getSimpleProductData();
        $productData['custom_attributes'] = [
            ['attribute_code' => self::KEY_SPECIAL_PRICE, 'value' => '1'],
        ];
        $this->saveProduct($productData);
        $response = $this->getProduct($productData[ProductInterface::SKU]);
        $customAttributes = $response['custom_attributes'];
        $this->assertNotEmpty($customAttributes);
        $missingAttributes = ['news_from_date', 'custom_design_from'];
        $expectedAttribute = ['special_price', 'special_from_date'];
        $attributeCodes = array_column($customAttributes, 'attribute_code');
        $this->assertCount(0, array_intersect($attributeCodes, $missingAttributes));
        $this->assertCount(2, array_intersect($attributeCodes, $expectedAttribute));
    }

    /**
     * Tests the ability to "reset" (nullify) a special_price by passing null in the web api request.
     *
     * Steps:
     *  1. Save the product with a special_price of $5.00
     *  2. Save the product with a special_price of null
     *  3. Confirm that the special_price is no longer set
     */
    public function testResetSpecialPrice()
    {
        $this->_markTestAsRestOnly(
            'In order to properly run this test for SOAP, XML must be used to specify <value></value> ' .
            'for the special_price value. Otherwise, the null value gets processed as a string and ' .
            'cast to a double value of 0.0.'
        );
        $productData = $this->getSimpleProductData();
        $productData['custom_attributes'] = [
            ['attribute_code' => self::KEY_SPECIAL_PRICE, 'value' => 5.00],
        ];
        $this->saveProduct($productData);
        $response = $this->getProduct($productData[ProductInterface::SKU]);
        $customAttributes = array_column($response['custom_attributes'], 'value', 'attribute_code');
        $this->assertEquals(5, $customAttributes[self::KEY_SPECIAL_PRICE]);
        $productData['custom_attributes'] = [
            ['attribute_code' => self::KEY_SPECIAL_PRICE, 'value' => null],
        ];
        $this->saveProduct($productData);
        $response = $this->getProduct($productData[ProductInterface::SKU]);
        $customAttributes = array_column($response['custom_attributes'], 'value', 'attribute_code');
        $this->assertArrayNotHasKey(self::KEY_SPECIAL_PRICE, $customAttributes);
    }

    /**
     * Test update status
     */
    public function testUpdateStatus()
    {
        // Create simple product
        $productData = [
            ProductInterface::SKU => "product_simple_502",
            ProductInterface::NAME => "Product Simple 502",
            ProductInterface::VISIBILITY => 4,
            ProductInterface::TYPE_ID => 'simple',
            ProductInterface::PRICE => 100,
            ProductInterface::STATUS => 0,
            ProductInterface::ATTRIBUTE_SET_ID => 4,
        ];

        // Save product with status disabled
        $this->saveProduct($productData);
        $response = $this->getProduct($productData[ProductInterface::SKU]);
        $this->assertEquals(0, $response['status']);

        // Update the product
        $productData[ProductInterface::PRICE] = 200;
        $this->saveProduct($productData);
        $response = $this->getProduct($productData[ProductInterface::SKU]);

        // Status should still be disabled
        $this->assertEquals(0, $response['status']);
        // Price should be updated
        $this->assertEquals(200, $response['price']);
    }

    /**
     * Test saving product with custom attribute of multiselect type
     *
     * 1. Create multi-select attribute
     * 2. Create product and set 2 options out of 3 to multi-select attribute
     * 3. Verify that 2 options are selected
     * 4. Unselect all options
     * 5. Verify that non options are selected
     *
     * @magentoApiDataFixture Magento/Catalog/_files/multiselect_attribute.php
     */
    public function testUpdateMultiselectAttributes()
    {
        $multiselectAttributeCode = 'multiselect_attribute';
        $multiselectOptions = $this->getAttributeOptions($multiselectAttributeCode);
        $option1 = $multiselectOptions[1]['value'];
        $option2 = $multiselectOptions[2]['value'];

        $productData = $this->getSimpleProductData();
        $productData['custom_attributes'] = [
            ['attribute_code' => $multiselectAttributeCode, 'value' => "{$option1},{$option2}"],
        ];
        $this->saveProduct($productData, 'all');

        $this->assertMultiselectValue(
            $productData[ProductInterface::SKU],
            $multiselectAttributeCode,
            "{$option1},{$option2}"
        );

        $productData['custom_attributes'] = [
            ['attribute_code' => $multiselectAttributeCode, 'value' => ""],
        ];
        $this->saveProduct($productData, 'all');
        $this->assertMultiselectValue(
            $productData[ProductInterface::SKU],
            $multiselectAttributeCode,
            ""
        );
    }

    /**
     * Get attribute options
     *
     * @param string $attributeCode
     * @return array|bool|float|int|string
     */
    private function getAttributeOptions($attributeCode)
    {
        $serviceInfo = [
            'rest' => [
                'resourcePath' => '/V1/products/attributes/' . $attributeCode . '/options',
                'httpMethod' => Request::HTTP_METHOD_GET,
            ],
            'soap' => [
                'service' => 'catalogProductAttributeOptionManagementV1',
                'serviceVersion' => 'V1',
                'operation' => 'catalogProductAttributeOptionManagementV1getItems',
            ],
        ];

        return $this->_webApiCall($serviceInfo, ['attributeCode' => $attributeCode]);
    }

    /**
     * Assert multiselect value
     *
     * @param string $productSku
     * @param string $multiselectAttributeCode
     * @param string $expectedMultiselectValue
     */
    private function assertMultiselectValue($productSku, $multiselectAttributeCode, $expectedMultiselectValue)
    {
        $response = $this->getProduct($productSku, 'all');
        $customAttributes = $response['custom_attributes'];
        $this->assertNotEmpty($customAttributes);
        $multiselectValue = null;
        foreach ($customAttributes as $customAttribute) {
            if ($customAttribute['attribute_code'] == $multiselectAttributeCode) {
                $multiselectValue = $customAttribute['value'];
                break;
            }
        }
        $this->assertEquals($expectedMultiselectValue, $multiselectValue);
    }

    /**
     * Test design settings authorization
     *
     * @magentoApiDataFixture Magento/User/_files/user_with_custom_role.php
     * @return void
     * @throws \Throwable
     */
    public function testSaveDesign(): void
    {
        //Updating our admin user's role to allow saving products but not their design settings.
        /** @var Role $role */
        $role = $this->roleFactory->create();
        $role->load('test_custom_role', 'role_name');
        /** @var Rules $rules */
        $rules = $this->rulesFactory->create();
        $rules->setRoleId($role->getId());
        $rules->setResources(['Magento_Catalog::products']);
        $rules->saveRel();
        //Using the admin user with custom role.
        $token = $this->adminTokens->createAdminAccessToken(
            'customRoleUser',
            \Magento\TestFramework\Bootstrap::ADMIN_PASSWORD
        );

        $productData = $this->getSimpleProductData();
        $productData['custom_attributes'][] = ['attribute_code' => 'custom_design', 'value' => '1'];

        //Creating new product with design settings.
        $exceptionMessage = null;
        try {
            $this->saveProduct($productData, null, $token);
        } catch (\Throwable $exception) {
            if ($restResponse = json_decode($exception->getMessage(), true)) {
                //REST
                $exceptionMessage = $restResponse['message'];
            } else {
                //SOAP
                $exceptionMessage = $exception->getMessage();
            }
        }
        //We don't have the permissions.
        $this->assertEquals('Not allowed to edit the product\'s design attributes', $exceptionMessage);

        //Updating the user role to allow access to design properties.
        /** @var Rules $rules */
        $rules = Bootstrap::getObjectManager()->create(Rules::class);
        $rules->setRoleId($role->getId());
        $rules->setResources(['Magento_Catalog::products', 'Magento_Catalog::edit_product_design']);
        $rules->saveRel();
        //Making the same request with design settings.
        $result = $this->saveProduct($productData, null, $token);
        $this->assertArrayHasKey('id', $result);
        //Product must be saved.
        $productSaved = $this->getProduct($productData[ProductInterface::SKU]);
        $savedCustomDesign = null;
        foreach ($productSaved['custom_attributes'] as $customAttribute) {
            if ($customAttribute['attribute_code'] === 'custom_design') {
                $savedCustomDesign = $customAttribute['value'];
                break;
            }
        }
        $this->assertEquals('1', $savedCustomDesign);
        $productData = $productSaved;

        //Updating our role to remove design properties access.
        /** @var Rules $rules */
        $rules = Bootstrap::getObjectManager()->create(Rules::class);
        $rules->setRoleId($role->getId());
        $rules->setResources(['Magento_Catalog::products']);
        $rules->saveRel();
        //Updating the product but with the same design properties values.
        //Removing the design attribute and keeping existing value.
        $attributes = $productData['custom_attributes'];
        foreach ($attributes as $i => $attribute) {
            if ($attribute['attribute_code'] === 'custom_design') {
                unset($productData['custom_attributes'][$i]);
                break;
            }
        }
        unset($attributes, $attribute, $i);
        $result = $this->updateProduct($productData, $token);
        //We haven't changed the design so operation is successful.
        $this->assertArrayHasKey('id', $result);

        //Changing a design property.
        $productData['custom_attributes'][] = ['attribute_code' => 'custom_design', 'value' => '2'];
        $exceptionMessage = null;
        try {
            $this->updateProduct($productData, $token);
        } catch (\Throwable $exception) {
            if ($restResponse = json_decode($exception->getMessage(), true)) {
                //REST
                $exceptionMessage = $restResponse['message'];
            } else {
                //SOAP
                $exceptionMessage = $exception->getMessage();
            }
        }
        //We don't have permissions to do that.
        $this->assertEquals('Not allowed to edit the product\'s design attributes', $exceptionMessage);
    }

    /**
     * @magentoApiDataFixture Magento/Store/_files/second_store.php
     */
    public function testImageRolesWithMultipleStores()
    {
        $this->_markTestAsRestOnly(
            'Test skipped due to known issue with SOAP. NULL value is cast to corresponding attribute type.'
        );
        $productData = $this->getSimpleProductData();
        $sku = $productData[ProductInterface::SKU];
        $defaultScope = Store::DEFAULT_STORE_ID;
        $defaultWebsiteId = $this->loadWebsiteByCode('base')->getId();
        $defaultStoreId = $this->loadStoreByCode('default')->getId();
        $secondStoreId = $this->loadStoreByCode('fixture_second_store')->getId();
        $encodedImage = $this->getTestImage();
        $imageRoles = ['image', 'small_image', 'thumbnail'];
        $img1 = uniqid('/t/e/test_image1_') . '.jpg';
        $img2 = uniqid('/t/e/test_image2_') . '.jpg';
        $productData['media_gallery_entries'] = [
            $this->getMediaGalleryData(basename($img1), $encodedImage, 1, 'front', false, ['image']),
            $this->getMediaGalleryData(basename($img2), $encodedImage, 2, 'back', false, ['small_image', 'thumbnail']),
        ];
        $productData[ProductInterface::EXTENSION_ATTRIBUTES_KEY]['website_ids'] = [
            $defaultWebsiteId,
        ];
        $response = $this->saveProduct($productData, 'all');
        if (isset($response['id'])) {
            $this->fixtureProducts[] = $sku;
        }
        $imageRolesPerStore = $this->getProductStoreImageRoles(
            $sku,
            [$defaultScope, $defaultStoreId, $secondStoreId],
            $imageRoles
        );
        $this->assertEquals($img1, $imageRolesPerStore[$defaultScope]['image']);
        $this->assertEquals($img2, $imageRolesPerStore[$defaultScope]['small_image']);
        $this->assertEquals($img2, $imageRolesPerStore[$defaultScope]['thumbnail']);
        $this->assertArrayNotHasKey($defaultStoreId, $imageRolesPerStore);
        $this->assertArrayNotHasKey($secondStoreId, $imageRolesPerStore);
        /**
         * Override image roles for default store
         */
        $storeProductData = $response;
        $storeProductData['media_gallery_entries'][0]['types'] = ['image', 'small_image', 'thumbnail'];
        $storeProductData['media_gallery_entries'][1]['types'] = [];
        $this->saveProduct($storeProductData, 'default');
        $imageRolesPerStore = $this->getProductStoreImageRoles(
            $sku,
            [$defaultScope, $defaultStoreId, $secondStoreId],
            $imageRoles
        );
        $this->assertEquals($img1, $imageRolesPerStore[$defaultScope]['image']);
        $this->assertEquals($img2, $imageRolesPerStore[$defaultScope]['small_image']);
        $this->assertEquals($img2, $imageRolesPerStore[$defaultScope]['thumbnail']);
        $this->assertEquals($img1, $imageRolesPerStore[$defaultStoreId]['image']);
        $this->assertEquals($img1, $imageRolesPerStore[$defaultStoreId]['small_image']);
        $this->assertEquals($img1, $imageRolesPerStore[$defaultStoreId]['thumbnail']);
        $this->assertArrayNotHasKey($secondStoreId, $imageRolesPerStore);
        /**
         * Inherit image roles from default scope
         */
        $customAttributes = $this->convertCustomAttributesToAssociativeArray($response['custom_attributes']);
        $customAttributes['image'] = null;
        $customAttributes['small_image'] = null;
        $customAttributes['thumbnail'] = null;
        $customAttributes = $this->convertAssociativeArrayToCustomAttributes($customAttributes);
        $storeProductData = $response;
        $storeProductData['media_gallery_entries'] = null;
        $storeProductData['custom_attributes'] = $customAttributes;
        $this->saveProduct($storeProductData, 'default');
        $imageRolesPerStore = $this->getProductStoreImageRoles(
            $sku,
            [$defaultScope, $defaultStoreId, $secondStoreId],
            $imageRoles
        );
        $this->assertEquals($img1, $imageRolesPerStore[$defaultScope]['image']);
        $this->assertEquals($img2, $imageRolesPerStore[$defaultScope]['small_image']);
        $this->assertEquals($img2, $imageRolesPerStore[$defaultScope]['thumbnail']);
        $this->assertArrayNotHasKey($defaultStoreId, $imageRolesPerStore);
        $this->assertArrayNotHasKey($secondStoreId, $imageRolesPerStore);
    }

    /**
     * Test that updating product image with same image name will result in incremented image name
     */
    public function testUpdateProductWithMediaGallery(): void
    {
        $productData = $this->getSimpleProductData();
        $sku = $productData[ProductInterface::SKU];
        $defaultScope = Store::DEFAULT_STORE_ID;
        $defaultWebsiteId = $this->loadWebsiteByCode('base')->getId();
        $encodedImage = $this->getTestImage();
        $imageRoles = ['image', 'small_image', 'thumbnail'];
        $img1 = uniqid('/t/e/test_image1_') . '.jpg';
        $img2 = uniqid('/t/e/test_image2_') . '.jpg';
        $productData['media_gallery_entries'] = [
            $this->getMediaGalleryData(basename($img1), $encodedImage, 1, 'front', false, ['image']),
            $this->getMediaGalleryData(basename($img2), $encodedImage, 2, 'back', false, ['small_image', 'thumbnail']),
        ];
        $productData[ProductInterface::EXTENSION_ATTRIBUTES_KEY]['website_ids'] = [
            $defaultWebsiteId,
        ];
        $response = $this->saveProduct($productData, 'all');
        if (isset($response['id'])) {
            $this->fixtureProducts[] = $sku;
        }
        $imageRolesPerStore = $this->getProductStoreImageRoles($sku, [$defaultScope], $imageRoles);
        $this->assertEquals($img1, $imageRolesPerStore[$defaultScope]['image']);
        $this->assertEquals($img2, $imageRolesPerStore[$defaultScope]['small_image']);
        $this->assertEquals($img2, $imageRolesPerStore[$defaultScope]['thumbnail']);
        $this->saveProduct($productData, 'all');
        $imageRolesPerStore = $this->getProductStoreImageRoles($sku, [$defaultScope], $imageRoles);
        $img1 = substr_replace($img1, '_1', -4, 0);
        $img2 = substr_replace($img2, '_1', -4, 0);
        $this->assertEquals($img1, $imageRolesPerStore[$defaultScope]['image']);
        $this->assertEquals($img2, $imageRolesPerStore[$defaultScope]['small_image']);
        $this->assertEquals($img2, $imageRolesPerStore[$defaultScope]['thumbnail']);
    }

    /**
     * Update url_key attribute and check it in url_rewrite collection
     *
     * @magentoApiDataFixture Magento/Catalog/_files/product_simple.php
     * @magentoConfigFixture default_store general/single_store_mode/enabled 1
     *
     * @return void
     */
    public function testUpdateUrlKeyAttribute(): void
    {
        $newUrlKey = 'my-new-url';

        $productData = [
            ProductInterface::SKU => 'simple',
            'custom_attributes' => [['attribute_code' => 'url_key', 'value' => $newUrlKey]],
        ];

        $this->updateProduct($productData);

        $urlRewriteCollection = $this->urlRewriteCollectionFactory->create();
        $urlRewriteCollection->addFieldToFilter(UrlRewrite::ENTITY_TYPE, 'product')
            ->addFieldToFilter('request_path', $newUrlKey . '.html');

        $this->assertCount(1, $urlRewriteCollection);
    }

    /**
     * @return string
     */
    private function getTestImage(): string
    {
        $testImagePath = __DIR__ . DIRECTORY_SEPARATOR . '_files' . DIRECTORY_SEPARATOR . 'test_image.jpg';
        // @codingStandardsIgnoreLine
        return base64_encode(file_get_contents($testImagePath));
    }

    /**
     * @return void
     */
    private function deleteFixtureProducts(): void
    {
        foreach ($this->fixtureProducts as $sku) {
            $this->deleteProduct($sku);
        }
        $this->fixtureProducts = [];
    }

    /**
     * @param string $code
     * @return StoreInterface
     */
    private function loadStoreByCode(string $code): StoreInterface
    {
        try {
            $store = Bootstrap::getObjectManager()->get(StoreRepository::class)->get($code);
        } catch (NoSuchEntityException $e) {
            $store = null;
            $this->fail("Couldn`t load store: {$code}");
        }
        return $store;
    }

    /**
     * @param string $sku
     * @param int|null $storeId
     * @return ProductInterface
     */
    private function getProductModel(string $sku, int $storeId = null): ProductInterface
    {
        try {
            $productRepository = Bootstrap::getObjectManager()->get(ProductRepositoryInterface::class);
            $product = $productRepository->get($sku, false, $storeId, true);
        } catch (NoSuchEntityException $e) {
            $product = null;
            $this->fail("Couldn`t load product: {$sku}");
        }
        return $product;
    }

    /**
     * @param string $sku
     * @param array $stores
     * @param array $roles
     * @return array
     */
    private function getProductStoreImageRoles(string $sku, array $stores, array $roles = []): array
    {
        /** @var Gallery $galleryResource */
        $galleryResource = Bootstrap::getObjectManager()->get(Gallery::class);
        $productModel = $this->getProductModel($sku);
        $imageRolesPerStore = [];
        foreach ($galleryResource->getProductImages($productModel, $stores) as $role) {
            if (empty($roles) || in_array($role['attribute_code'], $roles)) {
                $imageRolesPerStore[$role['store_id']][$role['attribute_code']] = $role['filepath'];
            }
        }
        return $imageRolesPerStore;
    }
}
