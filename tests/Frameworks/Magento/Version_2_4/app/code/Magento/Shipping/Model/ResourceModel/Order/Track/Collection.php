<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Shipping\Model\ResourceModel\Order\Track;

/**
 * Flat sales order shipment tracks collection
 *
 * @SuppressWarnings(PHPMD.DepthOfInheritance)
 */
class Collection extends \Magento\Sales\Model\ResourceModel\Order\Shipment\Track\Collection
{
    /**
     * Model initialization
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init(
            \Magento\Shipping\Model\Order\Track::class,
            \Magento\Sales\Model\ResourceModel\Order\Shipment\Track::class
        );
    }
}
