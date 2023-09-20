<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\CatalogInventory\Model\ResourceModel\Product;

use Magento\Catalog\Model\ResourceModel\Product\BaseSelectProcessorInterface;
use Magento\CatalogInventory\Model\Stock;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;

/**
 * Class StockStatusBaseSelectProcessor
 */
class StockStatusBaseSelectProcessor implements BaseSelectProcessorInterface
{
    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var \Magento\CatalogInventory\Api\StockConfigurationInterface
     */
    private $stockConfig;

    /**
     * @param ResourceConnection $resource
     * @param \Magento\CatalogInventory\Api\StockConfigurationInterface|null $stockConfig
     */
    public function __construct(
        ResourceConnection $resource,
        \Magento\CatalogInventory\Api\StockConfigurationInterface $stockConfig = null
    ) {
        $this->resource = $resource;
        $this->stockConfig = $stockConfig ?: ObjectManager::getInstance()
            ->get(\Magento\CatalogInventory\Api\StockConfigurationInterface::class);
    }

    /**
     * Add stock item filter to selects
     *
     * @param Select $select
     * @return Select
     */
    public function process(Select $select)
    {
        $stockStatusTable = $this->resource->getTableName('cataloginventory_stock_status');

        if (!$this->stockConfig->isShowOutOfStock()) {
            /** @var Select $select */
            $select->join(
                ['stock' => $stockStatusTable],
                sprintf('stock.product_id = %s.entity_id', BaseSelectProcessorInterface::PRODUCT_TABLE_ALIAS),
                []
            )
                ->where('stock.stock_status = ?', Stock::STOCK_IN_STOCK)
                ->where('stock.website_id = ?', 0);
        }

        return $select;
    }
}
