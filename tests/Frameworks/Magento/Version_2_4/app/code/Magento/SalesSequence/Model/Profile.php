<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\SalesSequence\Model;

use Magento\Framework\Model\AbstractModel;

/**
 * Class Profile
 *
 * @api
 * @since 100.0.2
 */
class Profile extends AbstractModel
{
    /**
     * @inheritdoc
     */
    protected function _construct()
    {
        $this->_init(\Magento\SalesSequence\Model\ResourceModel\Profile::class);
    }
}
