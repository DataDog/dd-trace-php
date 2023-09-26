<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Sitemap\Model\ResourceModel;

/**
 * Sitemap resource model
 *
 * @api
 * @since 100.0.2
 */
class Sitemap extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{
    /**
     * Init resource model
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('sitemap', 'sitemap_id');
    }
}
