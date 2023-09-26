<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Model\Indexer\Product\Category\Action;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Config;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Query\Generator as QueryGenerator;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Indexer\CacheContext;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Catalog\Model\Indexer\Product\Category as ProductCategoryIndexer;
use Magento\Catalog\Model\Indexer\Category\Product as CategoryProductIndexer;
use Magento\Catalog\Model\Indexer\Category\Product\TableMaintainer;
use Magento\Indexer\Model\WorkingStateProvider;

/**
 * Category rows indexer.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Rows extends \Magento\Catalog\Model\Indexer\Category\Product\AbstractAction
{
    /**
     * Limitation by products
     *
     * @var int[]
     */
    protected $limitationByProducts;

    /**
     * @var CacheContext
     */
    private $cacheContext;

    /**
     * @var EventManagerInterface|null
     */
    private $eventManager;

    /**
     * @var IndexerRegistry
     */
    private $indexerRegistry;

    /**
     * @var WorkingStateProvider
     */
    private $workingStateProvider;

    /**
     * @param ResourceConnection $resource
     * @param StoreManagerInterface $storeManager
     * @param Config $config
     * @param QueryGenerator|null $queryGenerator
     * @param MetadataPool|null $metadataPool
     * @param TableMaintainer|null $tableMaintainer
     * @param CacheContext|null $cacheContext
     * @param EventManagerInterface|null $eventManager
     * @param IndexerRegistry|null $indexerRegistry
     * @param WorkingStateProvider|null $workingStateProvider
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Preserve compatibility with the parent class
     */
    public function __construct(
        ResourceConnection $resource,
        StoreManagerInterface $storeManager,
        Config $config,
        QueryGenerator $queryGenerator = null,
        MetadataPool $metadataPool = null,
        ?TableMaintainer $tableMaintainer = null,
        CacheContext $cacheContext = null,
        EventManagerInterface $eventManager = null,
        IndexerRegistry $indexerRegistry = null,
        ?WorkingStateProvider $workingStateProvider = null
    ) {
        parent::__construct($resource, $storeManager, $config, $queryGenerator, $metadataPool, $tableMaintainer);
        $this->cacheContext = $cacheContext ?: ObjectManager::getInstance()->get(CacheContext::class);
        $this->eventManager = $eventManager ?: ObjectManager::getInstance()->get(EventManagerInterface::class);
        $this->indexerRegistry = $indexerRegistry ?: ObjectManager::getInstance()->get(IndexerRegistry::class);
        $this->workingStateProvider = $workingStateProvider ?:
            ObjectManager::getInstance()->get(WorkingStateProvider::class);
    }

    /**
     * Refresh entities index
     *
     * @param int[] $entityIds
     * @param bool $useTempTable
     * @return $this
     * @throws \Exception if metadataPool doesn't contain metadata for ProductInterface
     * @throws \DomainException
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function execute(array $entityIds = [], $useTempTable = false)
    {
        $idsToBeReIndexed = $this->getProductIdsWithParents($entityIds);

        $this->limitationByProducts = $idsToBeReIndexed;
        $this->useTempTable = $useTempTable;
        $indexer = $this->indexerRegistry->get(CategoryProductIndexer::INDEXER_ID);
        $workingState = $this->isWorkingState();

        if (!$indexer->isScheduled()
            || ($indexer->isScheduled() && !$useTempTable)
            || ($indexer->isScheduled() && $useTempTable && !$workingState)) {

            $affectedCategories = $this->getCategoryIdsFromIndex($idsToBeReIndexed);

            if ($useTempTable && !$workingState && $indexer->isScheduled()) {
                foreach ($this->storeManager->getStores() as $store) {
                    $this->connection->truncateTable($this->getIndexTable($store->getId()));
                }
            } else {
                $this->removeEntries();
            }
            $this->reindex();

            // get actual state
            $workingState = $this->isWorkingState();

            if ($useTempTable && !$workingState && $indexer->isScheduled()) {
                foreach ($this->storeManager->getStores() as $store) {
                    $this->connection->delete(
                        $this->tableMaintainer->getMainTable($store->getId()),
                        ['product_id IN (?)' => $this->limitationByProducts]
                    );
                    $select = $this->connection->select()
                        ->from($this->tableMaintainer->getMainReplicaTable($store->getId()));
                    $this->connection->query(
                        $this->connection->insertFromSelect(
                            $select,
                            $this->tableMaintainer->getMainTable($store->getId()),
                            [],
                            AdapterInterface::INSERT_ON_DUPLICATE
                        )
                    );
                }
            }

            $affectedCategories = array_merge($affectedCategories, $this->getCategoryIdsFromIndex($idsToBeReIndexed));

            $this->registerProducts($idsToBeReIndexed);
            $this->registerCategories($affectedCategories);
            $this->eventManager->dispatch('clean_cache_by_tags', ['object' => $this->cacheContext]);
        }

        return $this;
    }

    /**
     * Get state for current and shared indexer
     *
     * @return bool
     */
    private function isWorkingState() : bool
    {
        $indexer = $this->indexerRegistry->get(CategoryProductIndexer::INDEXER_ID);
        $sharedIndexer = $this->indexerRegistry->get(ProductCategoryIndexer::INDEXER_ID);
        return $this->workingStateProvider->isWorking($indexer->getId())
            || $this->workingStateProvider->isWorking($sharedIndexer->getId());
    }

    /**
     * Get IDs of parent products by their child IDs.
     *
     * Returns identifiers of parent product from the catalog_product_relation.
     * Please note that returned ids don't contain ids of passed child products.
     *
     * @param int[] $childProductIds
     * @return int[]
     * @throws \Exception if metadataPool doesn't contain metadata for ProductInterface
     * @throws \DomainException
     */
    private function getProductIdsWithParents(array $childProductIds): array
    {
        /** @var \Magento\Framework\EntityManager\EntityMetadataInterface $metadata */
        $metadata = $this->metadataPool->getMetadata(\Magento\Catalog\Api\Data\ProductInterface::class);
        $fieldForParent = $metadata->getLinkField();

        $select = $this->connection
            ->select()
            ->from(['relation' => $this->getTable('catalog_product_relation')], [])
            ->distinct(true)
            ->where('child_id IN (?)', $childProductIds, \Zend_Db::INT_TYPE)
            ->join(
                ['cpe' => $this->getTable('catalog_product_entity')],
                'relation.parent_id = cpe.' . $fieldForParent,
                ['cpe.entity_id']
            );

        $parentProductIds = $this->connection->fetchCol($select);
        $ids = array_unique(array_merge($childProductIds, $parentProductIds));
        foreach ($ids as $key => $id) {
            $ids[$key] = (int) $id;
        }

        return $ids;
    }

    /**
     * Register affected products
     *
     * @param array $entityIds
     * @return void
     */
    private function registerProducts($entityIds)
    {
        $this->cacheContext->registerEntities(Product::CACHE_TAG, $entityIds);
    }

    /**
     * Register categories assigned to products
     *
     * @param array $categoryIds
     * @return void
     */
    private function registerCategories(array $categoryIds)
    {
        if ($categoryIds) {
            $this->cacheContext->registerEntities(Category::CACHE_TAG, $categoryIds);
        }
    }

    /**
     * Remove index entries before reindexation
     *
     * @return void
     */
    protected function removeEntries()
    {
        foreach ($this->storeManager->getStores() as $store) {
            $this->connection->delete(
                $this->getIndexTable($store->getId()),
                ['product_id IN (?)' => $this->limitationByProducts]
            );
        }
    }

    /**
     * Retrieve select for reindex products of non anchor categories
     *
     * @param \Magento\Store\Model\Store $store
     * @return \Magento\Framework\DB\Select
     */
    protected function getNonAnchorCategoriesSelect(\Magento\Store\Model\Store $store)
    {
        $select = parent::getNonAnchorCategoriesSelect($store);
        return $select->where('ccp.product_id IN (?)', $this->limitationByProducts, \Zend_Db::INT_TYPE);
    }

    /**
     * Retrieve select for reindex products of non anchor categories
     *
     * @param \Magento\Store\Model\Store $store
     * @return \Magento\Framework\DB\Select
     */
    protected function getAnchorCategoriesSelect(\Magento\Store\Model\Store $store)
    {
        $select = parent::getAnchorCategoriesSelect($store);
        return $select->where('ccp.product_id IN (?)', $this->limitationByProducts, \Zend_Db::INT_TYPE);
    }

    /**
     * Get select for all products
     *
     * @param \Magento\Store\Model\Store $store
     * @return \Magento\Framework\DB\Select
     */
    protected function getAllProducts(\Magento\Store\Model\Store $store)
    {
        $select = parent::getAllProducts($store);
        return $select->where('cp.entity_id IN (?)', $this->limitationByProducts, \Zend_Db::INT_TYPE);
    }

    /**
     * Check whether select ranging is needed
     *
     * @return bool
     */
    protected function isRangingNeeded()
    {
        return false;
    }

    /**
     * Returns a list of category ids which are assigned to product ids in the index
     *
     * @param array $productIds
     * @return array
     */
    private function getCategoryIdsFromIndex(array $productIds): array
    {
        $categoryIds = [];
        foreach ($this->storeManager->getStores() as $store) {
            $storeCategories = $this->connection->fetchCol(
                $this->connection->select()
                    ->from($this->getIndexTable($store->getId()), ['category_id'])
                    ->where('product_id IN (?)', $productIds, \Zend_Db::INT_TYPE)
                    ->distinct()
            );
            $categoryIds[] = $storeCategories;
        }
        $categoryIds = array_merge([], ...$categoryIds);

        $parentCategories = [$categoryIds];
        foreach ($categoryIds as $categoryId) {
            $parentIds = explode('/', $this->getPathFromCategoryId($categoryId));
            $parentCategories[] = $parentIds;
        }
        $categoryIds = array_unique(array_merge([], ...$parentCategories));

        return $categoryIds;
    }
}
