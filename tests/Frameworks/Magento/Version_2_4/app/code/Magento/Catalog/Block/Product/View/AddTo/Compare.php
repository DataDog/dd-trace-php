<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Catalog\Block\Product\View\AddTo;

/**
 * Product view compare block
 *
 * @api
 * @since 101.0.1
 */
class Compare extends \Magento\Catalog\Block\Product\View
{
    /**
     * Return compare params
     *
     * @return string
     * @since 101.0.1
     */
    public function getPostDataParams()
    {
        $product = $this->getProduct();
        return $this->_compareProduct->getPostDataParams($product);
    }
}
