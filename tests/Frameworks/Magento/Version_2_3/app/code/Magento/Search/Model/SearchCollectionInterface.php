<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Search\Model;

/**
 * @api
 * @since 100.0.2
 */
interface SearchCollectionInterface extends \Traversable, \Countable
{
    /**
     * Set term filter
     *
     * @param string $term
     * @return self
     */
    public function addSearchFilter($term);
}
