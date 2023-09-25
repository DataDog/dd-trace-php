<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Observer\Compare;

use Magento\Framework\Event\ObserverInterface;
use Magento\Catalog\Model\Product\Compare\Item;

/**
 * Catalog Compare Item Model
 *
 */
class BindCustomerLoginObserver implements ObserverInterface
{
    /**
     * @param Item $item
     */
    public function __construct(
        Item $item
    ) {
        $this->item = $item;
    }

    /**
     * Customer login bind process
     * @param \Magento\Framework\Event\Observer $observer
     * @return $this
     *
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $this->item->bindCustomerLogin();

        return $this;
    }
}
