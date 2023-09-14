<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Customer\Api;

/**
 * @api
 * @since 100.0.2
 */
interface CustomerManagementInterface
{
    /**
     * Provide the number of customer count
     *
     * @return int
     */
    public function getCount();
}
