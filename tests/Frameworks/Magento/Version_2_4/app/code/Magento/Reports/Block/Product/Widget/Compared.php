<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Reports\Block\Product\Widget;

/**
 * Reports Recently Compared Products Widget
 * @deprecated 100.2.0 Since new frontend base widgets are provided
 * @see \Magento\Catalog\Block\Widget\RecentlyCompared
 */
class Compared extends \Magento\Reports\Block\Product\Compared implements \Magento\Widget\Block\BlockInterface
{
    /**
     * Internal constructor
     *
     * @return void
     */
    protected function _construct()
    {
        parent::_construct();
        $this->addColumnCountLayoutDepend('1column', 5)
            ->addColumnCountLayoutDepend('2columns-left', 4)
            ->addColumnCountLayoutDepend('2columns-right', 4)
            ->addColumnCountLayoutDepend('2columns', 3);
    }
}
