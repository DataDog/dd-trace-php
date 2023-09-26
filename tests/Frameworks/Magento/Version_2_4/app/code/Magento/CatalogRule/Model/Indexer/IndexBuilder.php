<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\CatalogRule\Model\Indexer;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ProductFactory;
use Magento\Catalog\Model\ResourceModel\Indexer\ActiveTableSwitcher;
use Magento\CatalogRule\Model\Indexer\IndexBuilder\ProductLoader;
use Magento\CatalogRule\Model\Indexer\IndexerTableSwapperInterface as TableSwapper;
use Magento\CatalogRule\Model\ResourceModel\Rule\Collection as RuleCollection;
use Magento\CatalogRule\Model\ResourceModel\Rule\CollectionFactory as RuleCollectionFactory;
use Magento\CatalogRule\Model\Rule;
use Magento\Eav\Model\Config;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Stdlib\DateTime;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Catalog rule index builder
 *
 * @api
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @since 100.0.2
 */
class IndexBuilder
{
    public const SECONDS_IN_DAY = 86400;

    /**
     * @var \Magento\Framework\EntityManager\MetadataPool
     * @deprecated 101.0.0
     * @see MAGETWO-64518
     * @since 100.1.0
     */
    protected $metadataPool;

    /**
     * CatalogRuleGroupWebsite columns list
     *
     * This array contain list of CatalogRuleGroupWebsite table columns
     *
     * @var array
     * @deprecated 101.0.0
     * @see MAGETWO-38167
     */
    protected $_catalogRuleGroupWebsiteColumnsList = ['rule_id', 'customer_group_id', 'website_id'];

    /**
     * @var ResourceConnection
     */
    protected $resource;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var RuleCollectionFactory
     */
    protected $ruleCollectionFactory;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var PriceCurrencyInterface
     */
    protected $priceCurrency;

    /**
     * @var Config
     */
    protected $eavConfig;

    /**
     * @var DateTime
     */
    protected $dateFormat;

    /**
     * @var DateTime\DateTime
     */
    protected $dateTime;

    /**
     * @var ProductFactory
     */
    protected $productFactory;

    /**
     * @var Product[]
     */
    protected $loadedProducts;

    /**
     * @var int
     */
    protected $batchCount;

    /**
     * @var \Magento\Framework\DB\Adapter\AdapterInterface
     */
    protected $connection;

    /**
     * @var ProductPriceCalculator
     */
    private $productPriceCalculator;

    /**
     * @var ReindexRuleProduct
     */
    private $reindexRuleProduct;

    /**
     * @var ReindexRuleGroupWebsite
     */
    private $reindexRuleGroupWebsite;

    /**
     * @var RuleProductsSelectBuilder
     */
    private $ruleProductsSelectBuilder;

    /**
     * @var ReindexRuleProductPrice
     */
    private $reindexRuleProductPrice;

    /**
     * @var RuleProductPricesPersistor
     */
    private $pricesPersistor;

    /**
     * @var TimezoneInterface|mixed
     */
    private $localeDate;

    /**
     * @var ActiveTableSwitcher|mixed
     */
    private $activeTableSwitcher;

    /**
     * @var TableSwapper
     */
    private $tableSwapper;

    /**
     * @var ProductLoader|mixed
     */
    private $productLoader;

    /**
     * @param RuleCollectionFactory $ruleCollectionFactory
     * @param PriceCurrencyInterface $priceCurrency
     * @param ResourceConnection $resource
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     * @param Config $eavConfig
     * @param DateTime $dateFormat
     * @param DateTime\DateTime $dateTime
     * @param ProductFactory $productFactory
     * @param int $batchCount
     * @param ProductPriceCalculator|null $productPriceCalculator
     * @param ReindexRuleProduct|null $reindexRuleProduct
     * @param ReindexRuleGroupWebsite|null $reindexRuleGroupWebsite
     * @param RuleProductsSelectBuilder|null $ruleProductsSelectBuilder
     * @param ReindexRuleProductPrice|null $reindexRuleProductPrice
     * @param RuleProductPricesPersistor|null $pricesPersistor
     * @param ActiveTableSwitcher|null $activeTableSwitcher
     * @param ProductLoader|null $productLoader
     * @param TableSwapper|null $tableSwapper
     * @param TimezoneInterface|null $localeDate
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function __construct(
        RuleCollectionFactory $ruleCollectionFactory,
        PriceCurrencyInterface $priceCurrency,
        ResourceConnection $resource,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger,
        Config $eavConfig,
        DateTime $dateFormat,
        DateTime\DateTime $dateTime,
        ProductFactory $productFactory,
        $batchCount = 1000,
        ProductPriceCalculator $productPriceCalculator = null,
        ReindexRuleProduct $reindexRuleProduct = null,
        ReindexRuleGroupWebsite $reindexRuleGroupWebsite = null,
        RuleProductsSelectBuilder $ruleProductsSelectBuilder = null,
        ReindexRuleProductPrice $reindexRuleProductPrice = null,
        RuleProductPricesPersistor $pricesPersistor = null,
        ActiveTableSwitcher $activeTableSwitcher = null,
        ProductLoader $productLoader = null,
        TableSwapper $tableSwapper = null,
        TimezoneInterface $localeDate = null
    ) {
        $this->resource = $resource;
        $this->connection = $resource->getConnection();
        $this->storeManager = $storeManager;
        $this->ruleCollectionFactory = $ruleCollectionFactory;
        $this->logger = $logger;
        $this->priceCurrency = $priceCurrency;
        $this->eavConfig = $eavConfig;
        $this->dateFormat = $dateFormat;
        $this->dateTime = $dateTime;
        $this->productFactory = $productFactory;
        $this->batchCount = $batchCount;

        $this->productPriceCalculator = $productPriceCalculator ?? ObjectManager::getInstance()->get(
            ProductPriceCalculator::class
        );
        $this->reindexRuleProduct = $reindexRuleProduct ?? ObjectManager::getInstance()->get(
            ReindexRuleProduct::class
        );
        $this->reindexRuleGroupWebsite = $reindexRuleGroupWebsite ?? ObjectManager::getInstance()->get(
            ReindexRuleGroupWebsite::class
        );
        $this->ruleProductsSelectBuilder = $ruleProductsSelectBuilder ?? ObjectManager::getInstance()->get(
            RuleProductsSelectBuilder::class
        );
        $this->reindexRuleProductPrice = $reindexRuleProductPrice ?? ObjectManager::getInstance()->get(
            ReindexRuleProductPrice::class
        );
        $this->pricesPersistor = $pricesPersistor ?? ObjectManager::getInstance()->get(
            RuleProductPricesPersistor::class
        );
        $this->activeTableSwitcher = $activeTableSwitcher ?? ObjectManager::getInstance()->get(
            ActiveTableSwitcher::class
        );
        $this->productLoader = $productLoader ?? ObjectManager::getInstance()->get(
            ProductLoader::class
        );
        $this->tableSwapper = $tableSwapper ??
            ObjectManager::getInstance()->get(TableSwapper::class);
        $this->localeDate = $localeDate ??
            ObjectManager::getInstance()->get(TimezoneInterface::class);
    }

    /**
     * Reindex by id
     *
     * @param int $id
     * @return void
     * @throws LocalizedException
     */
    public function reindexById($id)
    {
        try {
            $this->cleanProductIndex([$id]);

            $products = $this->productLoader->getProducts([$id]);
            $activeRules = $this->getActiveRules();
            foreach ($products as $product) {
                $this->applyRules($activeRules, $product);
            }

            $this->reindexRuleGroupWebsite->execute();
        } catch (\Exception $e) {
            $this->critical($e);
            throw new LocalizedException(
                __('Catalog rule indexing failed. See details in exception log.')
            );
        }
    }

    /**
     * Reindex by ids
     *
     * @param array $ids
     * @throws LocalizedException
     * @return void
     */
    public function reindexByIds(array $ids)
    {
        try {
            $this->doReindexByIds($ids);
        } catch (\Exception $e) {
            $this->critical($e);
            throw new LocalizedException(
                __("Catalog rule indexing failed. See details in exception log.")
            );
        }
    }

    /**
     * Reindex by ids. Template method
     *
     * @param array $ids
     * @return void
     */
    protected function doReindexByIds($ids)
    {
        $this->cleanProductIndex($ids);

        /** @var Rule[] $activeRules */
        $activeRules = $this->getActiveRules()->getItems();
        foreach ($activeRules as $rule) {
            $rule->setProductsFilter($ids);
            $this->reindexRuleProduct->execute($rule, $this->batchCount);
        }

        foreach ($ids as $productId) {
            $this->cleanProductPriceIndex([$productId]);
            $this->reindexRuleProductPrice->execute($this->batchCount, $productId);
        }

        $this->reindexRuleGroupWebsite->execute();
    }

    /**
     * Full reindex
     *
     * @throws LocalizedException
     * @return void
     */
    public function reindexFull()
    {
        try {
            $this->doReindexFull();
        } catch (\Exception $e) {
            $this->critical($e);
            throw new LocalizedException(
                __("Catalog rule indexing failed. See details in exception log.")
            );
        }
    }

    /**
     * Full reindex Template method
     *
     * @return void
     */
    protected function doReindexFull()
    {
        foreach ($this->getAllRules() as $rule) {
            $this->reindexRuleProduct->execute($rule, $this->batchCount, true);
        }

        $this->reindexRuleProductPrice->execute($this->batchCount, null, true);
        $this->reindexRuleGroupWebsite->execute(true);

        $this->tableSwapper->swapIndexTables(
            [
                $this->getTable('catalogrule_product'),
                $this->getTable('catalogrule_product_price'),
                $this->getTable('catalogrule_group_website')
            ]
        );
    }

    /**
     * Clean product index
     *
     * @param array $productIds
     * @return void
     */
    private function cleanProductIndex(array $productIds): void
    {
        $where = ['product_id IN (?)' => $productIds];
        $this->connection->delete($this->getTable('catalogrule_product'), $where);
    }

    /**
     * Clean product price index
     *
     * @param array $productIds
     * @return void
     */
    private function cleanProductPriceIndex(array $productIds): void
    {
        $where = ['product_id IN (?)' => $productIds];
        $this->connection->delete($this->getTable('catalogrule_product_price'), $where);
    }

    /**
     * Clean by product ids
     *
     * @param array $productIds
     * @return void
     */
    protected function cleanByIds($productIds)
    {
        $this->cleanProductIndex($productIds);
        $this->cleanProductPriceIndex($productIds);
    }

    /**
     * Assign product to rule
     *
     * @param Rule $rule
     * @param int $productEntityId
     * @param array $websiteIds
     * @return void
     */
    private function assignProductToRule(Rule $rule, int $productEntityId, array $websiteIds): void
    {
        $ruleId = (int) $rule->getId();
        $ruleProductTable = $this->getTable('catalogrule_product');
        $this->connection->delete(
            $ruleProductTable,
            [
                'rule_id = ?' => $ruleId,
                'product_id = ?' => $productEntityId,
            ]
        );

        $customerGroupIds = $rule->getCustomerGroupIds();
        $sortOrder = (int)$rule->getSortOrder();
        $actionOperator = $rule->getSimpleAction();
        $actionAmount = $rule->getDiscountAmount();
        $actionStop = $rule->getStopRulesProcessing();

        $rows = [];
        foreach ($websiteIds as $websiteId) {
            $scopeTz = new \DateTimeZone(
                $this->localeDate->getConfigTimezone(ScopeInterface::SCOPE_WEBSITE, $websiteId)
            );
            $fromTime = $rule->getFromDate()
                ? (new \DateTime($rule->getFromDate(), $scopeTz))->getTimestamp()
                : 0;
            $toTime = $rule->getToDate()
                ? (new \DateTime($rule->getToDate(), $scopeTz))->getTimestamp() + IndexBuilder::SECONDS_IN_DAY - 1
                : 0;
            foreach ($customerGroupIds as $customerGroupId) {
                $rows[] = [
                    'rule_id' => $ruleId,
                    'from_time' => $fromTime,
                    'to_time' => $toTime,
                    'website_id' => $websiteId,
                    'customer_group_id' => $customerGroupId,
                    'product_id' => $productEntityId,
                    'action_operator' => $actionOperator,
                    'action_amount' => $actionAmount,
                    'action_stop' => $actionStop,
                    'sort_order' => $sortOrder,
                ];

                if (count($rows) == $this->batchCount) {
                    $this->connection->insertMultiple($ruleProductTable, $rows);
                    $rows = [];
                }
            }
        }
        if ($rows) {
            $this->connection->insertMultiple($ruleProductTable, $rows);
        }
    }

    /**
     * Apply rule
     *
     * @param Rule $rule
     * @param Product $product
     * @return $this
     * @throws \Exception
     * @deprecated 101.1.5
     * @see ReindexRuleProduct::execute
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    protected function applyRule(Rule $rule, $product)
    {
        if ($rule->validate($product)) {
            $websiteIds = array_intersect($product->getWebsiteIds(), $rule->getWebsiteIds());
            $this->assignProductToRule($rule, $product->getId(), $websiteIds);
        }
        $this->reindexRuleProductPrice->execute($this->batchCount, $product->getId());
        $this->reindexRuleGroupWebsite->execute();

        return $this;
    }

    /**
     * Apply rules
     *
     * @param RuleCollection $ruleCollection
     * @param Product $product
     * @return void
     */
    private function applyRules(RuleCollection $ruleCollection, Product $product): void
    {
        foreach ($ruleCollection as $rule) {
            if (!$rule->validate($product)) {
                continue;
            }

            $websiteIds = array_intersect($product->getWebsiteIds(), $rule->getWebsiteIds());
            $this->assignProductToRule($rule, $product->getId(), $websiteIds);
        }

        $this->cleanProductPriceIndex([$product->getId()]);
        $this->reindexRuleProductPrice->execute($this->batchCount, $product->getId());
    }

    /**
     * Retrieve table name
     *
     * @param string $tableName
     * @return string
     */
    protected function getTable($tableName)
    {
        return $this->resource->getTableName($tableName);
    }

    /**
     * Update rule product data
     *
     * @param Rule $rule
     * @return $this
     * @deprecated 101.0.0
     * @see ReindexRuleProduct::execute
     */
    protected function updateRuleProductData(Rule $rule)
    {
        $ruleId = $rule->getId();
        if ($rule->getProductsFilter()) {
            $this->connection->delete(
                $this->getTable('catalogrule_product'),
                ['rule_id=?' => $ruleId, 'product_id IN (?)' => $rule->getProductsFilter()]
            );
        } else {
            $this->connection->delete(
                $this->getTable('catalogrule_product'),
                $this->connection->quoteInto('rule_id=?', $ruleId)
            );
        }

        $this->reindexRuleProduct->execute($rule, $this->batchCount);
        return $this;
    }

    /**
     * Apply all rules
     *
     * @param Product|null $product
     * @throws \Exception
     * @return $this
     * @deprecated 101.0.0
     * @see ReindexRuleProductPrice::execute
     * @see ReindexRuleGroupWebsite::execute
     */
    protected function applyAllRules(Product $product = null)
    {
        $this->reindexRuleProductPrice->execute($this->batchCount, $product->getId());
        $this->reindexRuleGroupWebsite->execute();
        return $this;
    }

    /**
     * Update CatalogRuleGroupWebsite data
     *
     * @return $this
     * @deprecated 101.0.0
     * @see ReindexRuleGroupWebsite::execute
     */
    protected function updateCatalogRuleGroupWebsiteData()
    {
        $this->reindexRuleGroupWebsite->execute();
        return $this;
    }

    /**
     * Clean rule price index
     *
     * @return $this
     */
    protected function deleteOldData()
    {
        $this->connection->delete($this->getTable('catalogrule_product_price'));
        return $this;
    }

    /**
     * Calculate rule product price
     *
     * @param array $ruleData
     * @param array $productData
     * @return float
     * @deprecated 101.0.0
     * @see ProductPriceCalculator::calculate
     */
    protected function calcRuleProductPrice($ruleData, $productData = null)
    {
        return $this->productPriceCalculator->calculate($ruleData, $productData);
    }

    /**
     * Get rule products statement
     *
     * @param int $websiteId
     * @param Product|null $product
     * @return \Zend_Db_Statement_Interface
     * @throws \Magento\Framework\Exception\LocalizedException
     * @deprecated 101.0.0
     * @see RuleProductsSelectBuilder::build
     */
    protected function getRuleProductsStmt($websiteId, Product $product = null)
    {
        return $this->ruleProductsSelectBuilder->build((int) $websiteId, (int) $product->getId());
    }

    /**
     * Save rule product prices
     *
     * @param array $arrData
     * @return $this
     * @throws \Exception
     * @deprecated 101.0.0
     * @see RuleProductPricesPersistor::execute
     */
    protected function saveRuleProductPrices($arrData)
    {
        $this->pricesPersistor->execute($arrData);
        return $this;
    }

    /**
     * Get active rules
     *
     * @return RuleCollection
     */
    protected function getActiveRules()
    {
        return $this->ruleCollectionFactory->create()->addFieldToFilter('is_active', 1);
    }

    /**
     * Get active rules
     *
     * @return RuleCollection
     */
    protected function getAllRules()
    {
        return $this->ruleCollectionFactory->create();
    }

    /**
     * Get product
     *
     * @param int $productId
     * @return Product
     */
    protected function getProduct($productId)
    {
        if (!isset($this->loadedProducts[$productId])) {
            $this->loadedProducts[$productId] = $this->productFactory->create()->load($productId);
        }
        return $this->loadedProducts[$productId];
    }

    /**
     * Log critical exception
     *
     * @param \Exception $e
     * @return void
     */
    protected function critical($e)
    {
        $this->logger->critical($e);
    }
}
