<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Model\Product\Condition;

use Magento\Eav\Model\Entity\Collection\AbstractCollection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;

/**
 * Interface \Magento\Catalog\Model\Product\Condition\ConditionInterface
 *
 * @api
 */
interface ConditionInterface
{
    /**
     * @param AbstractCollection $collection
     * @return $this
     */
    public function applyToCollection($collection);

    /**
     * @param AdapterInterface $dbAdapter
     * @return Select|string
     */
    public function getIdsSelect($dbAdapter);
}
