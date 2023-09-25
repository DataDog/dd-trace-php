<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\CatalogSearch\Model\Adapter\Mysql\Aggregation\DataProvider;

use Magento\CatalogInventory\Model\Configuration as CatalogInventoryConfiguration;
use Magento\CatalogInventory\Model\Stock;
use Magento\Customer\Model\Indexer\CustomerGroupDimensionProvider;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\ScopeResolverInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Search\Request\BucketInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Indexer\DimensionFactory;
use Magento\Framework\Search\Request\IndexScopeResolverInterface;
use Magento\Store\Model\Indexer\WebsiteDimensionProvider;

/**
 * Attribute query builder
 *
 * @deprecated 101.0.0
 * @see \Magento\ElasticSearch
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class QueryBuilder
{
    /**
     * @var DimensionFactory
     */
    private $dimensionFactory;

    /**
     * @var Resource
     */
    private $resource;

    /**
     * @var ScopeResolverInterface
     */
    private $scopeResolver;

    /**
     * @var CatalogInventoryConfiguration
     */
    private $inventoryConfig;

    /**
     * @var IndexScopeResolverInterface
     */
    private $priceTableResolver;

    /**
     * @param ResourceConnection $resource
     * @param ScopeResolverInterface $scopeResolver
     * @param CatalogInventoryConfiguration $inventoryConfig
     * @param IndexScopeResolverInterface $priceTableResolver
     * @param DimensionFactory|null $dimensionFactory
     */
    public function __construct(
        ResourceConnection $resource,
        ScopeResolverInterface $scopeResolver,
        CatalogInventoryConfiguration $inventoryConfig,
        IndexScopeResolverInterface $priceTableResolver = null,
        DimensionFactory $dimensionFactory = null
    ) {
        $this->resource = $resource;
        $this->scopeResolver = $scopeResolver;
        $this->inventoryConfig = $inventoryConfig;
        $this->priceTableResolver = $priceTableResolver
            ?: ObjectManager::getInstance()->get(IndexScopeResolverInterface::class);
        $this->dimensionFactory = $dimensionFactory ?: ObjectManager::getInstance()->get(DimensionFactory::class);
    }

    /**
     * Build select.
     *
     * @param AbstractAttribute $attribute
     * @param string $tableName
     * @param int $currentScope
     * @param int $customerGroupId
     *
     * @return Select
     */
    public function build(
        AbstractAttribute $attribute,
        string $tableName,
        int $currentScope,
        int $customerGroupId
    ) : Select {
        $select = $this->resource->getConnection()->select();
        $select->joinInner(
            ['entities' => $tableName],
            'main_table.entity_id  = entities.entity_id',
            []
        );

        if ($attribute->getAttributeCode() === 'price') {
            return $this->buildQueryForPriceAttribute($currentScope, $customerGroupId, $select);
        }

        return $this->buildQueryForAttribute($currentScope, $attribute, $select);
    }

    /**
     * Build select for price attribute.
     *
     * @param int $currentScope
     * @param int $customerGroupId
     * @param Select $select
     *
     * @return Select
     */
    private function buildQueryForPriceAttribute(
        int $currentScope,
        int $customerGroupId,
        Select $select
    ) : Select {
        /** @var \Magento\Store\Model\Store $store */
        $store = $this->scopeResolver->getScope($currentScope);
        if (!$store instanceof \Magento\Store\Model\Store) {
            throw new \RuntimeException('Illegal scope resolved');
        }
        $websiteId = $store->getWebsiteId();

        $tableName = $this->priceTableResolver->resolve(
            'catalog_product_index_price',
            [
                $this->dimensionFactory->create(
                    WebsiteDimensionProvider::DIMENSION_NAME,
                    (string)$websiteId
                ),
                $this->dimensionFactory->create(
                    CustomerGroupDimensionProvider::DIMENSION_NAME,
                    (string)$customerGroupId
                ),
            ]
        );
        $select->from(['main_table' => $tableName], null)
            ->columns([BucketInterface::FIELD_VALUE => 'main_table.min_price'])
            ->where('main_table.customer_group_id = ?', $customerGroupId)
            ->where('main_table.website_id = ?', $websiteId);

        return $select;
    }

    /**
     * Build select for attribute.
     *
     * @param int $currentScope
     * @param AbstractAttribute $attribute
     * @param Select $select
     *
     * @return Select
     */
    private function buildQueryForAttribute(
        int $currentScope,
        AbstractAttribute $attribute,
        Select $select
    ) : Select {
        $currentScopeId = $this->scopeResolver->getScope($currentScope)->getId();
        $table = $this->resource->getTableName(
            'catalog_product_index_eav' . ($attribute->getBackendType() === 'decimal' ? '_decimal' : '')
        );
        $select->from(['main_table' => $table], ['main_table.entity_id', 'main_table.value'])
            ->distinct()
            ->joinLeft(
                ['stock_index' => $this->resource->getTableName('cataloginventory_stock_status')],
                'main_table.source_id = stock_index.product_id',
                []
            )
            ->where('main_table.attribute_id = ?', $attribute->getAttributeId())
            ->where('main_table.store_id = ? ', $currentScopeId);

        if (!$this->inventoryConfig->isShowOutOfStock($currentScopeId)) {
            $select->where('stock_index.stock_status = ?', Stock::STOCK_IN_STOCK);
        }

        $parentSelect = $this->resource->getConnection()->select();
        $parentSelect->from(['main_table' => $select], ['main_table.value']);
        return $parentSelect;
    }
}
