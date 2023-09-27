<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Catalog\Api;

/**
 * @api
 * @since 100.0.2
 */
interface CategoryRepositoryInterface
{
    /**
     * Create category service
     *
     * @param \Magento\Catalog\Api\Data\CategoryInterface $category
     * @return \Magento\Catalog\Api\Data\CategoryInterface
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     */
    public function save(\Magento\Catalog\Api\Data\CategoryInterface $category);

    /**
     * Get info about category by category id
     *
     * @param int $categoryId
     * @param int $storeId
     * @return \Magento\Catalog\Api\Data\CategoryInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function get($categoryId, $storeId = null);

    /**
     * Delete category by identifier
     *
     * @param \Magento\Catalog\Api\Data\CategoryInterface $category category which will deleted
     * @return bool Will returned True if deleted
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\StateException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function delete(\Magento\Catalog\Api\Data\CategoryInterface $category);

    /**
     * Delete category by identifier
     *
     * @param int $categoryId
     * @return bool Will returned True if deleted
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\StateException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function deleteByIdentifier($categoryId);
}
