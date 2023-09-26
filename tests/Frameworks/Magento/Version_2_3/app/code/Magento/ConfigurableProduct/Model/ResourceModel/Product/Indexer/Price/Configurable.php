<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\ConfigurableProduct\Model\ResourceModel\Product\Indexer\Price;

use Magento\Catalog\Model\ResourceModel\Product\Indexer\Price\BasePriceModifier;
use Magento\Framework\Indexer\DimensionalIndexerInterface;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Catalog\Model\Indexer\Product\Price\TableMaintainer;
use Magento\Catalog\Model\ResourceModel\Product\Indexer\Price\Query\BaseFinalPrice;
use Magento\Catalog\Model\ResourceModel\Product\Indexer\Price\IndexTableStructureFactory;
use Magento\Catalog\Model\ResourceModel\Product\Indexer\Price\IndexTableStructure;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\App\ObjectManager;
use Magento\CatalogInventory\Model\Stock;
use Magento\CatalogInventory\Model\Configuration;

/**
 * Configurable Products Price Indexer Resource model
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Configurable implements DimensionalIndexerInterface
{
    /**
     * @var BaseFinalPrice
     */
    private $baseFinalPrice;

    /**
     * @var IndexTableStructureFactory
     */
    private $indexTableStructureFactory;

    /**
     * @var TableMaintainer
     */
    private $tableMaintainer;

    /**
     * @var MetadataPool
     */
    private $metadataPool;

    /**
     * @var \Magento\Framework\App\ResourceConnection
     */
    private $resource;

    /**
     * @var bool
     */
    private $fullReindexAction;

    /**
     * @var string
     */
    private $connectionName;

    /**
     * @var \Magento\Framework\DB\Adapter\AdapterInterface
     */
    private $connection;

    /**
     * @var BasePriceModifier
     */
    private $basePriceModifier;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @param BaseFinalPrice $baseFinalPrice
     * @param IndexTableStructureFactory $indexTableStructureFactory
     * @param TableMaintainer $tableMaintainer
     * @param MetadataPool $metadataPool
     * @param \Magento\Framework\App\ResourceConnection $resource
     * @param BasePriceModifier $basePriceModifier
     * @param bool $fullReindexAction
     * @param string $connectionName
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        BaseFinalPrice $baseFinalPrice,
        IndexTableStructureFactory $indexTableStructureFactory,
        TableMaintainer $tableMaintainer,
        MetadataPool $metadataPool,
        \Magento\Framework\App\ResourceConnection $resource,
        BasePriceModifier $basePriceModifier,
        $fullReindexAction = false,
        $connectionName = 'indexer',
        ScopeConfigInterface $scopeConfig = null
    ) {
        $this->baseFinalPrice = $baseFinalPrice;
        $this->indexTableStructureFactory = $indexTableStructureFactory;
        $this->tableMaintainer = $tableMaintainer;
        $this->connectionName = $connectionName;
        $this->metadataPool = $metadataPool;
        $this->resource = $resource;
        $this->fullReindexAction = $fullReindexAction;
        $this->basePriceModifier = $basePriceModifier;
        $this->scopeConfig = $scopeConfig ?: ObjectManager::getInstance()->get(ScopeConfigInterface::class);
    }

    /**
     * @inheritdoc
     *
     * @throws \Exception
     */
    public function executeByDimensions(array $dimensions, \Traversable $entityIds)
    {
        $this->tableMaintainer->createMainTmpTable($dimensions);

        $temporaryPriceTable = $this->indexTableStructureFactory->create([
            'tableName' => $this->tableMaintainer->getMainTmpTable($dimensions),
            'entityField' => 'entity_id',
            'customerGroupField' => 'customer_group_id',
            'websiteField' => 'website_id',
            'taxClassField' => 'tax_class_id',
            'originalPriceField' => 'price',
            'finalPriceField' => 'final_price',
            'minPriceField' => 'min_price',
            'maxPriceField' => 'max_price',
            'tierPriceField' => 'tier_price',
        ]);
        $select = $this->baseFinalPrice->getQuery(
            $dimensions,
            \Magento\ConfigurableProduct\Model\Product\Type\Configurable::TYPE_CODE,
            iterator_to_array($entityIds)
        );
        $this->tableMaintainer->insertFromSelect($select, $temporaryPriceTable->getTableName(), []);

        $this->basePriceModifier->modifyPrice($temporaryPriceTable, iterator_to_array($entityIds));
        $this->applyConfigurableOption($temporaryPriceTable, $dimensions, iterator_to_array($entityIds));
    }

    /**
     * Apply configurable option
     *
     * @param IndexTableStructure $temporaryPriceTable
     * @param array $dimensions
     * @param array $entityIds
     *
     * @return $this
     * @throws \Exception
     */
    private function applyConfigurableOption(
        IndexTableStructure $temporaryPriceTable,
        array $dimensions,
        array $entityIds
    ) {
        $temporaryOptionsTableName = 'catalog_product_index_price_cfg_opt_temp';
        $this->getConnection()->createTemporaryTableLike(
            $temporaryOptionsTableName,
            $this->getTable('catalog_product_index_price_cfg_opt_tmp'),
            true
        );

        $this->fillTemporaryOptionsTable($temporaryOptionsTableName, $dimensions, $entityIds);
        $this->updateTemporaryTable($temporaryPriceTable->getTableName(), $temporaryOptionsTableName);

        $this->getConnection()->delete($temporaryOptionsTableName);

        return $this;
    }

    /**
     * Put data into catalog product price indexer config option temp table
     *
     * @param string $temporaryOptionsTableName
     * @param array $dimensions
     * @param array $entityIds
     *
     * @return void
     * @throws \Exception
     */
    private function fillTemporaryOptionsTable(string $temporaryOptionsTableName, array $dimensions, array $entityIds)
    {
        $metadata = $this->metadataPool->getMetadata(\Magento\Catalog\Api\Data\ProductInterface::class);
        $linkField = $metadata->getLinkField();

        $select = $this->getConnection()->select()->from(
            ['i' => $this->getMainTable($dimensions)],
            []
        )->join(
            ['l' => $this->getTable('catalog_product_super_link')],
            'l.product_id = i.entity_id',
            []
        )->join(
            ['le' => $this->getTable('catalog_product_entity')],
            'le.' . $linkField . ' = l.parent_id',
            []
        );

        // Does not make sense to extend query if out of stock products won't appear in tables for indexing
        if ($this->isConfigShowOutOfStock()) {
            $select->join(
                ['si' => $this->getTable('cataloginventory_stock_item')],
                'si.product_id = l.product_id',
                []
            );
            $select->where('si.is_in_stock = ?', Stock::STOCK_IN_STOCK);
        }

        $select->columns(
            [
                'le.entity_id',
                'customer_group_id',
                'website_id',
                'MIN(final_price)',
                'MAX(final_price)',
                'MIN(tier_price)',
            ]
        )->group(
            ['le.entity_id', 'customer_group_id', 'website_id']
        );
        if ($entityIds !== null) {
            $select->where('le.entity_id IN (?)', $entityIds);
        }
        $this->tableMaintainer->insertFromSelect($select, $temporaryOptionsTableName, []);
    }

    /**
     * Update data in the catalog product price indexer temp table
     *
     * @param string $temporaryPriceTableName
     * @param string $temporaryOptionsTableName
     *
     * @return void
     */
    private function updateTemporaryTable(string $temporaryPriceTableName, string $temporaryOptionsTableName)
    {
        $table = ['i' => $temporaryPriceTableName];
        $selectForCrossUpdate = $this->getConnection()->select()->join(
            ['io' => $temporaryOptionsTableName],
            'i.entity_id = io.entity_id AND i.customer_group_id = io.customer_group_id' .
            ' AND i.website_id = io.website_id',
            []
        );
        // adds price of custom option, that was applied in DefaultPrice::_applyCustomOption
        $selectForCrossUpdate->columns(
            [
                'min_price' => new \Zend_Db_Expr('i.min_price - i.price + io.min_price'),
                'max_price' => new \Zend_Db_Expr('i.max_price - i.price + io.max_price'),
                'tier_price' => 'io.tier_price',
            ]
        );

        $query = $selectForCrossUpdate->crossUpdateFromSelect($table);
        $this->getConnection()->query($query);
    }

    /**
     * Get main table
     *
     * @param array $dimensions
     * @return string
     */
    private function getMainTable($dimensions)
    {
        if ($this->fullReindexAction) {
            return $this->tableMaintainer->getMainReplicaTable($dimensions);
        }
        return $this->tableMaintainer->getMainTableByDimensions($dimensions);
    }

    /**
     * Get connection
     *
     * @return \Magento\Framework\DB\Adapter\AdapterInterface
     * @throws \DomainException
     */
    private function getConnection(): \Magento\Framework\DB\Adapter\AdapterInterface
    {
        if ($this->connection === null) {
            $this->connection = $this->resource->getConnection($this->connectionName);
        }

        return $this->connection;
    }

    /**
     * Get table
     *
     * @param string $tableName
     * @return string
     */
    private function getTable($tableName)
    {
        return $this->resource->getTableName($tableName, $this->connectionName);
    }

    /**
     * Is flag Show Out Of Stock setted
     *
     * @return bool
     */
    private function isConfigShowOutOfStock(): bool
    {
        return $this->scopeConfig->isSetFlag(
            Configuration::XML_PATH_SHOW_OUT_OF_STOCK,
            ScopeInterface::SCOPE_STORE
        );
    }
}
