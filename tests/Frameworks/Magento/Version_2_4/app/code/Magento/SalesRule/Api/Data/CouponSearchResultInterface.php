<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\SalesRule\Api\Data;

/**
 * @api
 * @since 100.0.2
 */
interface CouponSearchResultInterface extends \Magento\Framework\Api\SearchResultsInterface
{
    /**
     * Get rules.
     *
     * @return \Magento\SalesRule\Api\Data\CouponInterface[]
     */
    public function getItems();

    /**
     * Set rules .
     *
     * @param \Magento\SalesRule\Api\Data\CouponInterface[] $items
     * @return $this
     */
    public function setItems(array $items = null);
}
