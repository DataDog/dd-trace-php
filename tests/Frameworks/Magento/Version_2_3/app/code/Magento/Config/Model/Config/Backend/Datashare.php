<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Config category field backend
 *
 * @author      Magento Core Team <core@magentocommerce.com>
 */
namespace Magento\Config\Model\Config\Backend;

/**
 * @api
 * @since 100.0.2
 */
class Datashare extends \Magento\Framework\App\Config\Value
{
    /**
     * @return $this
     */
    public function afterSave()
    {
        return $this;
    }
}
