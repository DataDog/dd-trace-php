<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Api\Search;

/**
 * Interface \Magento\Framework\Api\Search\AggregationValueInterface
 *
 */
interface AggregationValueInterface
{
    /**
     * Get aggregation
     *
     * @return string|array
     */
    public function getValue();

    /**
     * Get metrics
     *
     * @return mixed[]
     */
    public function getMetrics();
}
