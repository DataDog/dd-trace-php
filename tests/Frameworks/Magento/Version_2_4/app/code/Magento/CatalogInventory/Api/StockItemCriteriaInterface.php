<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\CatalogInventory\Api;

/**
 * Interface StockItemCriteriaInterface
 * @api
 * @since 100.0.2
 *
 * @deprecated 100.3.0 Replaced with Multi Source Inventory
 * @link https://devdocs.magento.com/guides/v2.4/inventory/index.html
 * @link https://devdocs.magento.com/guides/v2.4/inventory/inventory-api-reference.html
 */
interface StockItemCriteriaInterface extends \Magento\Framework\Api\CriteriaInterface
{
    /**
     * Add Criteria object
     *
     * @param \Magento\CatalogInventory\Api\StockItemCriteriaInterface $criteria
     * @return bool
     */
    public function addCriteria(\Magento\CatalogInventory\Api\StockItemCriteriaInterface $criteria);

    /**
     * Join Stock Status to collection
     *
     * @param int $storeId
     * @return bool
     */
    public function setStockStatus($storeId = null);

    /**
     * Add stock filter to collection
     *
     * @param \Magento\CatalogInventory\Api\Data\StockInterface $stock
     * @return bool
     */
    public function setStockFilter($stock);

    /**
     * Add scope filter to collection
     *
     * @param int $scope
     * @return bool
     */
    public function setScopeFilter($scope);

    /**
     * Add product filter to collection
     *
     * @param int|int[] $products
     * @return bool
     */
    public function setProductsFilter($products);

    /**
     * Add Managed Stock products filter to collection
     *
     * @param bool $isStockManagedInConfig
     * @return bool
     */
    public function setManagedFilter($isStockManagedInConfig);

    /**
     * Add filter by quantity to collection
     *
     * @param string $comparisonMethod
     * @param float $qty
     * @return bool
     */
    public function setQtyFilter($comparisonMethod, $qty);
}
