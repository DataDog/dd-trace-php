<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Admin tax rule content block
 *
 * @author      Magento Core Team <core@magentocommerce.com>
 */
namespace Magento\Tax\Block\Adminhtml;

/**
 * @api
 * @since 100.0.2
 */
class Rule extends \Magento\Backend\Block\Widget\Grid\Container
{
    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_controller = 'tax_rule';
        $this->_headerText = __('Manage Tax Rules');
        $this->_addButtonLabel = __('Add New Tax Rule');
        parent::_construct();
    }
}
