<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\AsynchronousOperations\Model\ResourceModel;

/**
 * Class Operation
 */
class Operation extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{
    /**
     * Initialize banner sales rule resource model
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('magento_operation', 'id');
    }
}
