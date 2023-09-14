<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\ConfigurableProduct\Model\Product\Type\Configurable;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Type\AbstractType;
use Magento\Customer\Model\Group;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Api\WebsiteRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Catalog\Model\Product\Price\GetPriceIndexDataByProductId;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\Store\ExecuteInStoreContext;
use PHPUnit\Framework\TestCase;

/**
 * Provides tests for configurable product pricing.
 *
 * @magentoDbIsolation disabled
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class PriceTest extends TestCase
{
    /**
     * @var ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var Price
     */
    private $priceModel;

    /**
     * @var GetPriceIndexDataByProductId
     */
    private $getPriceIndexDataByProductId;

    /**
     * @var WebsiteRepositoryInterface
     */
    private $websiteRepository;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var ExecuteInStoreContext
     */
    private $executeInStoreContext;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
        $this->priceModel = $this->objectManager->create(Price::class);
        $this->productRepository = $this->objectManager->get(ProductRepositoryInterface::class);
        $this->productRepository->cleanCache();
        $this->getPriceIndexDataByProductId = $this->objectManager->get(GetPriceIndexDataByProductId::class);
        $this->websiteRepository = $this->objectManager->get(WebsiteRepositoryInterface::class);
        $this->storeManager = $this->objectManager->get(StoreManagerInterface::class);
        $this->executeInStoreContext = $this->objectManager->get(ExecuteInStoreContext::class);
    }

    /**
     * @magentoDataFixture Magento/ConfigurableProduct/_files/tax_rule.php
     * @magentoDataFixture Magento/ConfigurableProduct/_files/product_configurable.php
     * @return void
     */
    public function testGetFinalPrice(): void
    {
        $this->assertPrice(10);
        $this->assertIndexTableData(
            'configurable',
            ['price' => 0, 'final_price' => 0, 'min_price' => 10, 'max_price' => 20, 'tier_price' => null]
        );
        $this->assertIndexTableData(
            'simple_10',
            ['price' => 10, 'final_price' => 10, 'min_price' => 10, 'max_price' => 10, 'tier_price' => null]
        );
        $this->assertIndexTableData(
            'simple_20',
            ['price' => 20, 'final_price' => 20, 'min_price' => 20, 'max_price' => 20, 'tier_price' => null]
        );
    }

    /**
     * @magentoDataFixture Magento/ConfigurableProduct/_files/configurable_product_with_price_on_second_website.php
     * @return void
     */
    public function testGetFinalPriceOnSecondWebsite(): void
    {
        $this->executeInStoreContext->execute('fixture_second_store', [$this, 'assertPrice'], 10);
        $this->executeInStoreContext->execute(
            'fixture_second_store',
            [$this, 'assertIndexTableData'],
            'configurable',
            ['price' => 0, 'final_price' => 0, 'min_price' => 10, 'max_price' => 30, 'tier_price' => null]
        );
        $this->executeInStoreContext->execute(
            'fixture_second_store',
            [$this, 'assertIndexTableData'],
            'simple_option_1',
            ['price' => 20, 'final_price' => 10, 'min_price' => 10, 'max_price' => 10, 'tier_price' => null]
        );
        $this->executeInStoreContext->execute(
            'fixture_second_store',
            [$this, 'assertIndexTableData'],
            'simple_option_2',
            ['price' => 40, 'final_price' => 30, 'min_price' => 30, 'max_price' => 30, 'tier_price' => null]
        );
        $this->assertPrice(150);
        $this->assertIndexTableData(
            'configurable',
            ['price' => 0, 'final_price' => 0, 'min_price' => 150, 'max_price' => 150, 'tier_price' => null]
        );
        $this->assertIndexTableData(
            'simple_option_1',
            ['price' => 150, 'final_price' => 150, 'min_price' => 150, 'max_price' => 150, 'tier_price' => null]
        );
        $this->assertIndexTableData(
            'simple_option_2',
            ['price' => 150, 'final_price' => 150, 'min_price' => 150, 'max_price' => 150, 'tier_price' => null]
        );
    }

    /**
     * @magentoConfigFixture current_store tax/display/type 1
     * @magentoDataFixture Magento/ConfigurableProduct/_files/tax_rule.php
     * @magentoDataFixture Magento/ConfigurableProduct/_files/product_configurable.php
     * @return void
     */
    public function testGetFinalPriceExcludingTax(): void
    {
        $this->assertPrice(10);
    }

    /**
     * @magentoConfigFixture current_store tax/display/type 2
     * @magentoDataFixture Magento/ConfigurableProduct/_files/tax_rule.php
     * @magentoDataFixture Magento/ConfigurableProduct/_files/product_configurable.php
     * @return void
     */
    public function testGetFinalPriceIncludingTax(): void
    {
        //lowest price of configurable variation + 10%
        $this->assertPrice(11);
    }

    /**
     * @magentoConfigFixture current_store tax/display/type 3
     * @magentoDataFixture Magento/ConfigurableProduct/_files/tax_rule.php
     * @magentoDataFixture Magento/ConfigurableProduct/_files/product_configurable.php
     * @return void
     */
    public function testGetFinalPriceIncludingExcludingTax(): void
    {
        //lowest price of configurable variation + 10%
        $this->assertPrice(11);
    }

    /**
     * @magentoDataFixture Magento/ConfigurableProduct/_files/tax_rule.php
     * @magentoDataFixture Magento/ConfigurableProduct/_files/product_configurable.php
     * @return void
     */
    public function testGetFinalPriceWithSelectedSimpleProduct(): void
    {
        $product = $this->productRepository->get('configurable');
        $product->addCustomOption('simple_product', 20, $this->getProduct('simple_20'));
        $this->assertPrice(20, $product);
    }

    /**
     * @magentoDataFixture Magento/ConfigurableProduct/_files/configurable_product_with_custom_option_and_simple_tier_price.php
     * @return void
     */
    public function testGetFinalPriceWithCustomOptionAndSimpleTierPrice(): void
    {
        $configurable = $this->getProduct('configurable');
        $this->assertIndexTableData(
            'configurable',
            ['price' => 0, 'final_price' => 0, 'min_price' => 9, 'max_price' => 30, 'tier_price' => 15]
        );
        $this->assertIndexTableData(
            'simple_10',
            ['price' => 10, 'final_price' => 9, 'min_price' => 9, 'max_price' => 9, 'tier_price' => null]
        );
        $this->assertIndexTableData(
            'simple_20',
            ['price' => 20, 'final_price' => 15, 'min_price' => 15, 'max_price' => 15, 'tier_price' => 15]
        );
        $optionId = $configurable->getOptions()[0]->getId();
        $configurable->addCustomOption(AbstractType::OPTION_PREFIX . $optionId, 'text');
        $configurable->addCustomOption('option_ids', $optionId);
        //  First simple special price (9) + Option price (15)
        $this->assertPrice(24, $configurable);
        $configurable->addCustomOption('simple_product', 20, $this->productRepository->get('simple_20'));
        //  Second simple tier price (15) + Option price (15)
        $this->assertPrice(30, $configurable);
    }

    /**
     * Asserts price data in index table.
     *
     * @param string $sku
     * @param array $expectedPrices
     * @return void
     */
    public function assertIndexTableData(string $sku, array $expectedPrices): void
    {
        $data = $this->getPriceIndexDataByProductId->execute(
            (int)$this->getProduct($sku)->getId(),
            Group::NOT_LOGGED_IN_ID,
            (int)$this->storeManager->getStore()->getWebsiteId()
        );
        $data = reset($data);
        foreach ($expectedPrices as $column => $price) {
            $this->assertEquals($price, $data[$column], $column);
        }
    }

    /**
     * Asserts product final price.
     *
     * @param float $expectedPrice
     * @param ProductInterface|null $product
     * @return void
     */
    public function assertPrice(float $expectedPrice, ?ProductInterface $product = null): void
    {
        $product = $product ?: $this->getProduct('configurable');
        // final price is the lowest price of configurable variations
        $this->assertEquals(
            round($expectedPrice, 2),
            round((float) $this->priceModel->getFinalPrice(1, $product), 2)
        );
    }

    /**
     * Loads product by sku.
     *
     * @param string $sku
     * @return ProductInterface
     */
    private function getProduct(string $sku): ProductInterface
    {
        return $this->productRepository->get($sku, false, $this->storeManager->getStore()->getId(), true);
    }
}
