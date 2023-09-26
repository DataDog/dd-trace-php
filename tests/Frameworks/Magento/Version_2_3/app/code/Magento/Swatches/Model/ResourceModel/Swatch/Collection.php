<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Swatches\Model\ResourceModel\Swatch;

/**
 * @codeCoverageIgnore
 * Swatch Resource Collection
 * @api
 * @since 100.0.2
 */
class Collection extends \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
{
    /**
     * Standard collection initialization
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init(\Magento\Swatches\Model\Swatch::class, \Magento\Swatches\Model\ResourceModel\Swatch::class);
    }

    /**
     * Adding store filter to collection
     *
     * @param int $storeId
     * @return $this
     */
    public function addStoreFilter($storeId)
    {
        $this->addFieldToFilter('main_table.store_id', ['eq' => $storeId]);
        return $this;
    }

    /**
     * Adding filter by Attribute options ids.
     *
     * @param array $optionsIds
     * @return $this
     */
    public function addFilterByOptionsIds(array $optionsIds)
    {
        $this->addFieldToFilter('main_table.option_id', ['in' => $optionsIds]);
        return $this;
    }
}
