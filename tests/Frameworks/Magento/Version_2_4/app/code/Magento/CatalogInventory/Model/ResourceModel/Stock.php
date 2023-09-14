<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\CatalogInventory\Model\ResourceModel;

use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\CatalogInventory\Model\Configuration;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Context;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Stock resource model
 */
class Stock extends AbstractDb implements QtyCounterInterface
{
    /**
     * @var StockConfigurationInterface
     */
    protected $stockConfiguration;

    /**
     * Is initialized configuration flag
     *
     * @var bool
     */
    protected $_isConfig;

    /**
     * Manage Stock flag
     *
     * @var bool
     */
    protected $_isConfigManageStock;

    /**
     * Backorders
     *
     * @var bool
     */
    protected $_isConfigBackorders;

    /**
     * Minimum quantity allowed in shopping card
     *
     * @var int
     */
    protected $_configMinQty;

    /**
     * Product types that could have quantities
     *
     * @var array
     */
    protected $_configTypeIds;

    /**
     * Notify for quantity below _configNotifyStockQty value
     *
     * @var int
     */
    protected $_configNotifyStockQty;

    /**
     * Core store config
     *
     * @var ScopeConfigInterface
     */
    protected $_scopeConfig;

    /**
     * @var DateTime
     */
    protected $dateTime;

    /**
     * @var StoreManagerInterface
     * @deprecated 100.1.0
     */
    protected $storeManager;

    /**
     * @param Context $context
     * @param ScopeConfigInterface $scopeConfig
     * @param DateTime $dateTime
     * @param StockConfigurationInterface $stockConfiguration
     * @param StoreManagerInterface $storeManager
     * @param string $connectionName
     */
    public function __construct(
        Context $context,
        ScopeConfigInterface $scopeConfig,
        DateTime $dateTime,
        StockConfigurationInterface $stockConfiguration,
        StoreManagerInterface $storeManager,
        $connectionName = null
    ) {
        parent::__construct($context, $connectionName);
        $this->_scopeConfig = $scopeConfig;
        $this->dateTime = $dateTime;
        $this->stockConfiguration = $stockConfiguration;
        $this->storeManager = $storeManager;
    }

    /**
     * Define main table and initialize connection
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('cataloginventory_stock', 'stock_id');
    }

    /**
     * Lock Stock Item records.
     *
     * @param int[] $productIds
     * @param int $websiteId
     * @return array
     */
    public function lockProductsStock(array $productIds, $websiteId)
    {
        if (empty($productIds)) {
            return [];
        }
        $itemTable = $this->getTable('cataloginventory_stock_item');

        //get indexed entries for row level lock instead of table lock
        $itemIds = [];
        $preSelect = $this->getConnection()->select()->from($itemTable, 'item_id')
            ->where('website_id = ?', $websiteId)
            ->where('product_id IN(?)', $productIds, \Zend_Db::INT_TYPE);
        foreach ($this->getConnection()->query($preSelect)->fetchAll() as $item) {
            $itemIds[] = (int)$item['item_id'];
        }

        $select = $this->getConnection()->select()->from(['si' => $itemTable])
            ->where('item_id IN (?)', $itemIds, \Zend_Db::INT_TYPE)
            ->forUpdate(true);

        $productTable = $this->getTable('catalog_product_entity');
        $selectProducts = $this->getConnection()->select()->from(['p' => $productTable], [])
            ->where('entity_id IN (?)', $productIds, \Zend_Db::INT_TYPE)
            ->columns(
                [
                    'product_id' => 'entity_id',
                    'type_id' => 'type_id',
                ]
            );
        $items = [];

        foreach ($this->getConnection()->query($select)->fetchAll() as $si) {
            $items[$si['product_id']] = $si;
        }
        foreach ($this->getConnection()->fetchAll($selectProducts) as $p) {
            $items[$p['product_id']]['type_id'] = $p['type_id'];
        }

        return $items;
    }

    /**
     * @inheritdoc
     */
    public function correctItemsQty(array $items, $websiteId, $operator)
    {
        if (empty($items)) {
            return;
        }

        $connection = $this->getConnection();
        $conditions = [];
        foreach ($items as $productId => $qty) {
            $case = $connection->quoteInto('?', $productId);
            $result = $connection->quoteInto("qty{$operator}?", $qty);
            $conditions[$case] = $result;
        }

        $value = $connection->getCaseSql('product_id', $conditions, 'qty');
        $where = ['product_id IN (?)' => array_keys($items), 'website_id = ?' => $websiteId];

        $connection->beginTransaction();
        $connection->update($this->getTable('cataloginventory_stock_item'), ['qty' => $value], $where);
        $connection->commit();
    }

    /**
     * Load some inventory configuration settings
     *
     * @return void
     */
    protected function _initConfig()
    {
        if (!$this->_isConfig) {
            $configMap = [
                '_isConfigManageStock' => Configuration::XML_PATH_MANAGE_STOCK,
                '_isConfigBackorders' => Configuration::XML_PATH_BACKORDERS,
                '_configMinQty' => Configuration::XML_PATH_MIN_QTY,
                '_configNotifyStockQty' => Configuration::XML_PATH_NOTIFY_STOCK_QTY,
            ];

            foreach ($configMap as $field => $const) {
                $this->{$field} = (int) $this->_scopeConfig->getValue(
                    $const,
                    ScopeInterface::SCOPE_STORE
                );
            }

            $this->_isConfig = true;
            $this->_configTypeIds = array_keys($this->stockConfiguration->getIsQtyTypeIds(true));
        }
    }

    /**
     * Set items out of stock basing on their quantities and config settings
     *
     * @deprecated 100.2.5
     * @see \Magento\CatalogInventory\Model\ResourceModel\Stock\Item::updateSetOutOfStock
     * @param string|int $website
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @return void
     */
    public function updateSetOutOfStock($website = null)
    {
        $websiteId = $this->stockConfiguration->getDefaultScopeId();
        $this->_initConfig();
        $connection = $this->getConnection();
        $values = ['is_in_stock' => 0, 'stock_status_changed_auto' => 1];

        $select = $connection->select()->from($this->getTable('catalog_product_entity'), 'entity_id')
            ->where('type_id IN(?)', $this->_configTypeIds);

        $where = sprintf(
            'website_id = %1$d' .
            ' AND is_in_stock = 1' .
            ' AND ((use_config_manage_stock = 1 AND 1 = %2$d) OR (use_config_manage_stock = 0 AND manage_stock = 1))' .
            ' AND ((use_config_backorders = 1 AND %3$d = %4$d) OR (use_config_backorders = 0 AND backorders = %3$d))' .
            ' AND ((use_config_min_qty = 1 AND qty <= %5$d) OR (use_config_min_qty = 0 AND qty <= min_qty))' .
            ' AND product_id IN (%6$s)',
            $websiteId,
            $this->_isConfigManageStock,
            \Magento\CatalogInventory\Model\Stock::BACKORDERS_NO,
            $this->_isConfigBackorders,
            $this->_configMinQty,
            $select->assemble()
        );

        $connection->update($this->getTable('cataloginventory_stock_item'), $values, $where);
    }

    /**
     * Set items in stock basing on their quantities and config settings
     *
     * @deprecated 100.2.5
     * @see \Magento\CatalogInventory\Model\ResourceModel\Stock\Item::updateSetInStock
     * @param int|string $website
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @return void
     */
    public function updateSetInStock($website)
    {
        $websiteId = $this->stockConfiguration->getDefaultScopeId();
        $this->_initConfig();
        $connection = $this->getConnection();
        $values = ['is_in_stock' => 1];

        $select = $connection->select()->from($this->getTable('catalog_product_entity'), 'entity_id')
            ->where('type_id IN(?)', $this->_configTypeIds);

        $where = sprintf(
            'website_id = %1$d' .
            ' AND is_in_stock = 0' .
            ' AND stock_status_changed_auto = 1' .
            ' AND ((use_config_manage_stock = 1 AND 1 = %2$d) OR (use_config_manage_stock = 0 AND manage_stock = 1))' .
            ' AND ((use_config_min_qty = 1 AND qty > %3$d) OR (use_config_min_qty = 0 AND qty > min_qty))' .
            ' AND product_id IN (%4$s)',
            $websiteId,
            $this->_isConfigManageStock,
            $this->_configMinQty,
            $select->assemble()
        );

        $connection->update($this->getTable('cataloginventory_stock_item'), $values, $where);
    }

    /**
     * Update items low stock date basing on their quantities and config settings
     *
     * @deprecated 100.2.5
     * @see \Magento\CatalogInventory\Model\ResourceModel\Stock\Item::updateLowStockDate
     * @param int|string $website
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @return void
     */
    public function updateLowStockDate($website)
    {
        $websiteId = $this->stockConfiguration->getDefaultScopeId();
        $this->_initConfig();

        $connection = $this->getConnection();
        $condition = $connection->quoteInto(
            '(use_config_notify_stock_qty = 1 AND qty < ?)',
            $this->_configNotifyStockQty
        ) . ' OR (use_config_notify_stock_qty = 0 AND qty < notify_stock_qty)';
        $currentDbTime = $connection->quoteInto('?', $this->dateTime->gmtDate());
        $conditionalDate = $connection->getCheckSql($condition, $currentDbTime, 'NULL');

        $value = ['low_stock_date' => new \Zend_Db_Expr($conditionalDate)];

        $select = $connection->select()->from($this->getTable('catalog_product_entity'), 'entity_id')
            ->where('type_id IN(?)', $this->_configTypeIds);

        $where = sprintf(
            'website_id = %1$d' .
            ' AND ((use_config_manage_stock = 1 AND 1 = %2$d) OR (use_config_manage_stock = 0 AND manage_stock = 1))' .
            ' AND product_id IN (%3$s)',
            $websiteId,
            $this->_isConfigManageStock,
            $select->assemble()
        );

        $connection->update($this->getTable('cataloginventory_stock_item'), $value, $where);
    }

    /**
     * Add low stock filter to product collection
     *
     * @param Collection $collection
     * @param array $fields
     * @return $this
     */
    public function addLowStockFilter(Collection $collection, $fields)
    {
        $this->_initConfig();
        $connection = $collection->getSelect()->getConnection();
        $qtyIf = $connection->getCheckSql(
            'invtr.use_config_notify_stock_qty > 0',
            $this->_configNotifyStockQty,
            'invtr.notify_stock_qty'
        );
        $conditions = [
            [
                $connection->prepareSqlCondition('invtr.use_config_manage_stock', 1),
                $connection->prepareSqlCondition($this->_isConfigManageStock, 1),
                $connection->prepareSqlCondition('invtr.qty', ['lt' => $qtyIf]),
            ],
            [
                $connection->prepareSqlCondition('invtr.use_config_manage_stock', 0),
                $connection->prepareSqlCondition('invtr.manage_stock', 1)
            ],
        ];

        $where = [];
        foreach ($conditions as $k => $part) {
            $where[$k] = join(' ' . Select::SQL_AND . ' ', $part);
        }

        $where = $connection->prepareSqlCondition(
            'invtr.low_stock_date',
            ['notnull' => true]
        ) . ' ' . Select::SQL_AND . ' ((' . join(
            ') ' . Select::SQL_OR . ' (',
            $where
        ) . '))';

        $collection->joinTable(
            ['invtr' => 'cataloginventory_stock_item'],
            'product_id = entity_id',
            $fields,
            $where
        );
        return $this;
    }
}
