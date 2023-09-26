<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Store\Api;

use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Group repository interface
 *
 * @api
 * @since 100.0.2
 */
interface GroupRepositoryInterface
{
    /**
     * Retrieve group by id
     *
     * @param int $id
     * @return \Magento\Store\Api\Data\GroupInterface
     * @throws NoSuchEntityException
     */
    public function get($id);

    /**
     * Retrieve list of all groups
     *
     * @return \Magento\Store\Api\Data\GroupInterface[]
     */
    public function getList();

    /**
     * Clear cached entities
     *
     * @return void
     */
    public function clean();
}
