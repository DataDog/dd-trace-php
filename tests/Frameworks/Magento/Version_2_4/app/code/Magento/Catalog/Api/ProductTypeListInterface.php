<?php
/**
 * Product type provider
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Api;

/**
 * @api
 * @since 100.0.2
 */
interface ProductTypeListInterface
{
    /**
     * Retrieve available product types
     *
     * @return \Magento\Catalog\Api\Data\ProductTypeInterface[]
     */
    public function getProductTypes();
}
