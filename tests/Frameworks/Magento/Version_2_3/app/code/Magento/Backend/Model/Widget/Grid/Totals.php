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
class Totals extends \Magento\Backend\Model\Widget\Grid\AbstractTotals
{
    /**
     * Count collection column sum based on column index
     *
     * @param string $index
     * @param \Magento\Framework\Data\Collection $collection
     * @return float|int
     */
    protected function _countSum($index, $collection)
    {
        $sum = 0;
        foreach ($collection as $item) {
            if (!$item->hasChildren()) {
                $sum += $item[$index];
            } else {
                $sum += $this->_countSum($index, $item->getChildren());
            }
        }
        return $sum;
    }

    /**
     * Count collection column average based on column index
     *
     * @param string $index
     * @param \Magento\Framework\Data\Collection $collection
     * @return float|int
     */
    protected function _countAverage($index, $collection)
    {
        $itemsCount = 0;
        foreach ($collection as $item) {
            if (!$item->hasChildren()) {
                $itemsCount += 1;
            } else {
                $itemsCount += count($item->getChildren());
            }
        }

        return $itemsCount ? $this->_countSum($index, $collection) / $itemsCount : $itemsCount;
    }
}
