<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Backend\Model\Widget\Grid;

/**
 * @api
 * @since 100.0.2
 */
interface TotalsInterface
{
    /**
     * Return object contains totals for all items in collection
     *
     * @abstract
     * @param \Magento\Framework\Data\Collection $collection
     * @return \Magento\Framework\DataObject
     * @api
     */
    public function countTotals($collection);
}
