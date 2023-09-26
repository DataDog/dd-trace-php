<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\View\Design\Theme\File;

/**
 * Design Theme File collection interface
 *
 * @api
 */
interface CollectionInterface
{
    /**
     * Get items
     *
     * @return \Magento\Framework\View\Design\Theme\FileInterface[]
     */
    public function getItems();

    /**
     * Filter out files that do not belong to a theme
     *
     * @param \Magento\Framework\View\Design\ThemeInterface $theme
     * @return CollectionInterface
     */
    public function addThemeFilter(\Magento\Framework\View\Design\ThemeInterface $theme);

    /**
     * Set default order
     *
     * @param string $direction
     * @return CollectionInterface
     */
    public function setDefaultOrder($direction = 'ASC');

    /**
     * Add field filter to collection
     *
     * @param string $field
     * @param null|string|array $condition
     * @return CollectionInterface
     */
    public function addFieldToFilter($field, $condition = null);
}
